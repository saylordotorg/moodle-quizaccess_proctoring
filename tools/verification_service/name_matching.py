"""Name matching between a student's profile name and the text read from their ID (CPIT-477).

Shared by the Lambda (lambda_function.py) and the ASGI bridge (rekognition_bridge.py). Pure Python
with no AWS calls, so it is tested offline.

Text detection reads Latin, Cyrillic and Arabic script. A name printed only in another script
cannot be read from the ID, and is reported as unreadable instead of scoring a silent 0. Cyrillic
and Greek are romanised here; other scripts come romanised from Moodle in the name variants it
sends.
"""

from difflib import SequenceMatcher
import re
import unicodedata
from typing import Any, Iterable

# Cyrillic letters that text detection returns inside Latin words: they are misreadings of the
# Latin letter they look like, so they are folded back before matching OCR text. Never applied to
# the profile name, where Cyrillic is the student's real name.
OCR_CONFUSABLES = str.maketrans({
    "А": "A", "В": "B", "Е": "E", "К": "K", "М": "M", "Н": "H", "О": "O",
    "Р": "P", "С": "C", "Т": "T", "У": "Y", "Х": "X",
    "а": "a", "в": "b", "е": "e", "к": "k", "м": "m", "н": "h", "о": "o",
    "р": "p", "с": "c", "т": "t", "у": "y", "х": "x",
})

# Latin letters that do not decompose to a base letter plus an accent.
LATIN_FOLDS = str.maketrans({
    "ø": "o", "æ": "ae", "œ": "oe", "ł": "l", "đ": "d", "ð": "d", "þ": "th", "ı": "i", "ŋ": "n",
})

# Romanisation as used in the machine-readable zone of passports (ICAO 9303).
CYRILLIC_LATIN = {
    "а": "a", "б": "b", "в": "v", "г": "g", "д": "d", "е": "e", "ё": "e", "ж": "zh", "з": "z",
    "и": "i", "й": "i", "к": "k", "л": "l", "м": "m", "н": "n", "о": "o", "п": "p", "р": "r",
    "с": "s", "т": "t", "у": "u", "ф": "f", "х": "kh", "ц": "ts", "ч": "ch", "ш": "sh",
    "щ": "shch", "ъ": "ie", "ы": "y", "ь": "", "э": "e", "ю": "iu", "я": "ia",
    "є": "ie", "і": "i", "ї": "i", "ґ": "g", "ў": "u", "ј": "j", "љ": "lj", "њ": "nj",
    "ћ": "c", "ђ": "dj", "џ": "dz", "ѓ": "g", "ќ": "k", "ѕ": "dz",
}
GREEK_LATIN = {
    "α": "a", "β": "v", "γ": "g", "δ": "d", "ε": "e", "ζ": "z", "η": "i", "θ": "th", "ι": "i",
    "κ": "k", "λ": "l", "μ": "m", "ν": "n", "ξ": "x", "ο": "o", "π": "p", "ρ": "r", "σ": "s",
    "ς": "s", "τ": "t", "υ": "y", "φ": "f", "χ": "ch", "ψ": "ps", "ω": "o",
}
# Vowel pairs that passports (ELOT 743) romanise as a pair.
GREEK_DIGRAPHS = {"ου": "ou", "αυ": "av", "ευ": "ev"}

NICKNAME_GROUPS = [
    ("alex", "alexander", "alexandra"),
    ("andy", "andrew"),
    ("ben", "benjamin"),
    ("beth", "elizabeth", "liz"),
    ("bill", "billy", "will", "william"),
    ("bob", "bobby", "rob", "robert"),
    ("chris", "christopher", "christine", "christina"),
    ("dan", "danny", "daniel"),
    ("dave", "david"),
    ("ed", "eddie", "edward"),
    ("frank", "francis"),
    ("fred", "frederick"),
    ("jim", "jimmy", "james"),
    ("joe", "joey", "joseph"),
    ("jon", "john", "jonathan"),
    ("kate", "katherine", "kathryn", "katie"),
    ("ken", "kenneth"),
    ("matt", "matthew"),
    ("mike", "michael"),
    ("nick", "nicholas"),
    ("pat", "patrick", "patricia"),
    ("rick", "richard", "ricky"),
    ("sam", "samantha", "samuel"),
    ("steve", "stephen", "steven"),
    ("sue", "susan", "susanne"),
    ("tom", "tommy", "thomas"),
    ("tony", "anthony"),
]
NICKNAME_ALIASES = {
    name: set(group)
    for group in NICKNAME_GROUPS
    for name in group
}

# Words printed on ID documents as labels, not names. Text made only of these (and of numbers and
# single letters) holds no name, so the name is reported as unreadable rather than scored.
DOCUMENT_LABEL_WORDS = frozenset("""
    address america apellidos authority birth card class code country date dd department dl dob
    document donor driver drivers duplicate endorsements end exp expiration expires expiry eyes
    female given hair height hgt id identification identity iss issue issued license licence
    male motor name names national nationality nombre nom none number of operator organ passport
    permit place prenom real republic rest restrictions sex signature state states surname the
    type united usa valid vehicle vehicles veteran weight wgt
""".split())


def has_name_candidate(text: str) -> bool:
    """Whether normalised ID text has a word that could be part of a name, not only labels."""
    return any(
        len(word) > 1 and word.isalpha() and word not in DOCUMENT_LABEL_WORDS
        for word in text.split()
    )


# The most profile name variants considered, and their longest accepted length.
MAX_VARIANTS = 40
MAX_NAME_LENGTH = 600

REASON_MATCHED = "matched"
REASON_FUZZY = "fuzzy"
REASON_UNREADABLE = "unreadable"
REASON_SCRIPT = "script_not_supported"


def fold_confusables(value: str, everywhere: bool = False) -> str:
    """Fold Cyrillic look-alikes to Latin in words that also have Latin letters, or in every word.

    A word mixing the two scripts is a Latin word misread by OCR. A word entirely in Cyrillic is
    usually Cyrillic, so by default it is left alone.
    """
    words = re.split(r"(\s+)", value)
    out = []
    for word in words:
        mixed = any("a" <= char.lower() <= "z" for char in word)
        out.append(word.translate(OCR_CONFUSABLES) if everywhere or mixed else word)
    return "".join(out)


def normalized_text(value: str, ocr: bool = False) -> str:
    """Casefold, strip accents and punctuation, and keep letters and digits of every script.

    Latin input normalises as before (a-z, 0-9, single spaces). Other scripts are no longer
    erased to nothing, which used to make every non-Latin name score 0.
    """
    if ocr:
        value = fold_confusables(value)
    value = unicodedata.normalize("NFKD", value)
    value = "".join(char for char in value if not unicodedata.combining(char))
    value = value.casefold().translate(LATIN_FOLDS)
    value = "".join(fold_latin(char) if char.isalnum() else " " for char in value)
    return re.sub(r"\s+", " ", value).strip()


def fold_latin(char: str) -> str:
    """A Latin letter outside a-z that has no decomposition, such as ħ, as its base letter.

    Unicode names it "LATIN SMALL LETTER H WITH STROKE"; the letter after LETTER is the base.
    Letters of other scripts, and digits, are returned unchanged.
    """
    if "a" <= char <= "z" or not char.isalpha():
        return char
    match = re.match(r"LATIN (?:SMALL|CAPITAL) LETTER ([A-Z]{1,2})(?: |$)", unicodedata.name(char, ""))
    return match.group(1).lower() if match else char


def romanize(value: str) -> str:
    """Romanise Cyrillic and Greek letters; anything else is kept as it is."""
    # Accents off first: an accented vowel such as the iota in "Νίκος" is a separate code point
    # that the tables below do not list.
    value = unicodedata.normalize("NFKD", value)
    value = "".join(char for char in value if not unicodedata.combining(char)).lower()
    for digraph, latin in GREEK_DIGRAPHS.items():
        value = value.replace(digraph, latin)
    out = []
    for char in value:
        lower = char.lower()
        if lower in CYRILLIC_LATIN:
            out.append(CYRILLIC_LATIN[lower])
        elif lower in GREEK_LATIN:
            out.append(GREEK_LATIN[lower])
        else:
            out.append(char)
    return "".join(out)


def scripts(value: str) -> set[str]:
    """The scripts of the letters in a value, by the first word of their Unicode names."""
    return {unicodedata.name(char, "?").split(" ")[0] for char in value if char.isalpha()}


def ocr_forms(lines: list[str]) -> list[list[str]]:
    """The ID text read three ways: as read, with every look-alike folded to Latin, romanised.

    Text detection reads Latin, Cyrillic and Arabic. As read compares a Cyrillic name with a
    Cyrillic profile name; folded catches a Latin name OCR spelled with Cyrillic look-alikes;
    romanised compares a Cyrillic or Greek ID with a Latin profile name.
    """
    forms = [
        [normalized_text(line, ocr=True) for line in lines],
        [normalized_text(fold_confusables(line, everywhere=True)) for line in lines],
        [normalized_text(romanize(line)) for line in lines],
    ]
    unique: list[list[str]] = []
    for form in forms:
        if form not in unique:
            unique.append(form)
    return unique


def format_id_name(value: str) -> str:
    return " ".join(token.capitalize() for token in normalized_text(value, ocr=True).split() if token)


def extract_id_name(lines: list[str]) -> str:
    numbered_fields: dict[str, str] = {}
    for line in lines:
        match = re.match(r"^([12])\s+(.+)$", normalized_text(line, ocr=True))
        if match:
            numbered_fields[match.group(1)] = match.group(2)

    if numbered_fields.get("1") and numbered_fields.get("2"):
        return format_id_name(f"{numbered_fields['2']} {numbered_fields['1']}")

    if numbered_fields.get("1"):
        return format_id_name(numbered_fields["1"])

    if numbered_fields.get("2"):
        return format_id_name(numbered_fields["2"])

    return ""


def token_matches_id(profile_token: str, id_tokens: set[str]) -> bool:
    if profile_token in id_tokens:
        return True

    return bool(NICKNAME_ALIASES.get(profile_token, set()) & id_tokens)


def profile_names(fullname: Any, firstname: Any, lastname: Any, variants: Any) -> list[str]:
    """The profile name and the variants Moodle sent, deduplicated, as given (not normalised)."""
    names: list[str] = []
    primary = fullname if isinstance(fullname, str) and fullname.strip() else " ".join(
        part for part in (firstname, lastname) if isinstance(part, str) and part.strip()
    )
    candidates: list[Any] = [primary]
    if isinstance(variants, (list, tuple)):
        candidates.extend(list(variants)[:MAX_VARIANTS])
    for candidate in candidates:
        if isinstance(candidate, str) and candidate.strip() and len(candidate) <= MAX_NAME_LENGTH:
            if candidate not in names:
                names.append(candidate)
    return names


def _score_one(profile: str, lines: list[str], normalized_lines: list[str], combined: str) -> tuple[float, str]:
    tokens = [token for token in profile.split(" ") if token]
    combined_tokens = set(combined.split())
    if tokens and all(token_matches_id(token, combined_tokens) for token in tokens):
        return 100.0, ""

    best_line = ""
    best_score = 0.0
    for line, normalized_line in zip(lines, normalized_lines):
        score = SequenceMatcher(None, profile, normalized_line).ratio() * 100
        if score > best_score:
            best_score = score
            best_line = line

    combined_score = SequenceMatcher(None, profile, combined).ratio() * 100
    if combined_score > best_score:
        best_score = combined_score
        best_line = " ".join(lines)
    return best_score, best_line


def score_name_match(lines: list[str], names: Iterable[str]) -> dict[str, Any]:
    """Score the ID text against every profile name variant and keep the best.

    Each profile name is compared as written and romanised, with each form of the ID text from
    ocr_forms(); the best pair counts. Returns name_score, extracted_name,
    matched_profile_name, name_match_reason and name_readable.
    """
    forms = [(form, " ".join(line for line in form if line)) for form in ocr_forms(lines)]
    readable = any(has_name_candidate(combined) for _, combined in forms)
    result: dict[str, Any] = {
        "name_score": 0.0,
        "extracted_name": "",
        "matched_profile_name": "",
        "name_match_reason": REASON_UNREADABLE,
        "name_readable": readable,
    }
    if not readable:
        return result

    result["extracted_name"] = extract_id_name(lines)
    comparable = False
    best_line = ""
    for name in names:
        for candidate in (name, romanize(name)):
            profile = normalized_text(candidate)
            if not profile:
                continue
            for normalized_lines, combined in forms:
                # Two names in different scripts cannot be compared letter by letter.
                if not scripts(profile) & scripts(combined):
                    continue
                comparable = True
                score, line = _score_one(profile, lines, normalized_lines, combined)
                if score > result["name_score"]:
                    result["name_score"] = score
                    result["matched_profile_name"] = name
                    best_line = line
                if result["name_score"] >= 100.0:
                    break
            if result["name_score"] >= 100.0:
                break
        if result["name_score"] >= 100.0:
            break

    if not comparable:
        result["name_match_reason"] = REASON_SCRIPT
        return result
    result["name_match_reason"] = REASON_MATCHED if result["name_score"] >= 100.0 else REASON_FUZZY
    if not result["extracted_name"]:
        result["extracted_name"] = result["matched_profile_name"] if result["name_score"] >= 100.0 else best_line
    return result
