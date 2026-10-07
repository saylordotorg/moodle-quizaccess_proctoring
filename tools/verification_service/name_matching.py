"""Name matching between a student's profile name and the text read from their ID (CPIT-477).

Shared by the Lambda (lambda_function.py) and the ASGI bridge (rekognition_bridge.py). Pure Python
with no AWS calls, so it is tested offline.

Text detection only reads Latin script, so a name printed only in another script cannot be read
from the ID. Such an ID is reported as unreadable instead of scoring a silent 0. A profile name
written in Cyrillic or Greek is romanised here; other scripts come romanised from Moodle in the
name variants it sends.
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

# The most profile name variants considered, and their longest accepted length.
MAX_VARIANTS = 40
MAX_NAME_LENGTH = 600

REASON_MATCHED = "matched"
REASON_FUZZY = "fuzzy"
REASON_UNREADABLE = "unreadable"
REASON_SCRIPT = "script_not_supported"


def normalized_text(value: str, ocr: bool = False) -> str:
    """Casefold, strip accents and punctuation, and keep letters and digits of every script.

    Latin input normalises as before (a-z, 0-9, single spaces). Other scripts are no longer
    erased to nothing, which used to make every non-Latin name score 0.
    """
    if ocr:
        value = value.translate(OCR_CONFUSABLES)
    value = unicodedata.normalize("NFKD", value)
    value = "".join(char for char in value if not unicodedata.combining(char))
    value = value.casefold().translate(LATIN_FOLDS)
    value = "".join(char if char.isalnum() else " " for char in value)
    return re.sub(r"\s+", " ", value).strip()


def romanize(value: str) -> str:
    """Romanise Cyrillic and Greek letters; anything else is kept as it is."""
    out = []
    for char in unicodedata.normalize("NFC", value):
        lower = char.lower()
        if lower in CYRILLIC_LATIN:
            out.append(CYRILLIC_LATIN[lower])
        elif lower in GREEK_LATIN:
            out.append(GREEK_LATIN[lower])
        else:
            out.append(char)
    return "".join(out)


def is_latin(value: str) -> bool:
    """Whether every letter in the (normalised) value is a-z."""
    return all(("a" <= char <= "z") or not char.isalpha() for char in value)


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

    Returns name_score, extracted_name, matched_profile_name, name_match_reason and name_readable.
    """
    normalized_lines = [normalized_text(line, ocr=True) for line in lines]
    combined = " ".join(line for line in normalized_lines if line)
    readable = any(char.isalpha() for char in combined)
    result: dict[str, Any] = {
        "name_score": 0.0,
        "extracted_name": "",
        "matched_profile_name": "",
        "name_match_reason": REASON_UNREADABLE,
        "name_readable": readable,
    }
    if not readable:
        return result

    extracted_name = extract_id_name(lines)
    result["extracted_name"] = extracted_name
    comparable = False
    best_line = ""
    for name in names:
        for candidate in (name, romanize(name)):
            profile = normalized_text(candidate)
            # The ID text is Latin script; a profile name still in another script cannot be compared.
            if not profile or not is_latin(profile):
                continue
            comparable = True
            score, line = _score_one(profile, lines, normalized_lines, combined)
            if score > result["name_score"]:
                result["name_score"] = score
                result["matched_profile_name"] = name
                best_line = line
            if score >= 100.0:
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
