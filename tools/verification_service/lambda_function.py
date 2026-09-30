import base64
from difflib import SequenceMatcher
import hmac
import json
import math
import os
import re
import unicodedata
from typing import Any

import boto3
from botocore.exceptions import BotoCoreError, ClientError


API_KEY = os.getenv("FACE_API_KEY", "")
AWS_REGION = os.getenv("AWS_REGION", "us-east-1")
SIMILARITY_THRESHOLD = float(os.getenv("FACE_SIMILARITY_THRESHOLD", "80"))
ID_FACE_THRESHOLD = float(os.getenv("ID_FACE_THRESHOLD", "80"))
ID_NAME_THRESHOLD = float(os.getenv("ID_NAME_THRESHOLD", "80"))
MAX_REQUEST_BYTES = 6 * 1024 * 1024
MAX_IMAGE_BYTES = 5 * 1024 * 1024
MAX_IMAGE_TEXT = ((MAX_IMAGE_BYTES + 2) // 3) * 4 + 64

rekognition = boto3.client("rekognition", region_name=AWS_REGION)

OCR_CONFUSABLES = str.maketrans({
    "А": "A", "В": "B", "Е": "E", "К": "K", "М": "M", "Н": "H", "О": "O",
    "Р": "P", "С": "C", "Т": "T", "У": "Y", "Х": "X",
    "а": "a", "в": "b", "е": "e", "к": "k", "м": "m", "н": "h", "о": "o",
    "р": "p", "с": "c", "т": "t", "у": "y", "х": "x",
})

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


def response(status_code: int, body: dict[str, Any]) -> dict[str, Any]:
    return {
        "statusCode": status_code,
        "headers": {"Content-Type": "application/json"},
        "body": json.dumps(body),
    }


def get_header(event: dict[str, Any], name: str) -> str:
    headers = event.get("headers") or {}
    for key, value in headers.items():
        if key.lower() == name.lower():
            return value or ""
    return ""


def decode_image(image_data: str) -> bytes:
    if not isinstance(image_data, str) or not image_data or len(image_data) > MAX_IMAGE_TEXT:
        raise ValueError("Invalid image data.")
    if "," in image_data:
        image_data = image_data.split(",", 1)[1]
    decoded = base64.b64decode(image_data, validate=True)
    if not decoded or len(decoded) > MAX_IMAGE_BYTES:
        raise ValueError("Invalid image size.")
    return decoded


def valid_threshold(value: Any, default: float) -> float:
    threshold = float(default if value is None else value)
    if isinstance(value, bool) or not math.isfinite(threshold) or not 0 < threshold <= 100:
        raise ValueError("Threshold must be greater than zero and at most 100.")
    return threshold


def payload_bool(payload: dict[str, Any], key: str, default: bool = True) -> bool:
    value = payload.get(key)
    if value is None:
        return default
    if isinstance(value, bool):
        return value
    if isinstance(value, (int, float)):
        return value != 0

    normalized = str(value).strip().lower()
    if normalized in {"0", "false", "no", "off"}:
        return False
    if normalized in {"1", "true", "yes", "on"}:
        return True
    return default


def best_similarity(compare_response: dict[str, Any]) -> float:
    matches = compare_response.get("FaceMatches", [])
    if not matches:
        return 0.0
    return max(float(match.get("Similarity", 0)) for match in matches)


def normalized_text(value: str) -> str:
    value = unicodedata.normalize("NFKD", value.translate(OCR_CONFUSABLES))
    value = "".join(char for char in value if not unicodedata.combining(char))
    return re.sub(r"[^a-z0-9 ]+", " ", value.lower()).strip()


def format_id_name(value: str) -> str:
    return " ".join(token.capitalize() for token in normalized_text(value).split() if token)


def extract_id_name(lines: list[str]) -> str:
    numbered_fields: dict[str, str] = {}
    for line in lines:
        match = re.match(r"^([12])\s+(.+)$", normalized_text(line))
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


def detect_id_text(id_image: bytes) -> list[str]:
    detect_response = rekognition.detect_text(Image={"Bytes": id_image})
    return [
        str(item["DetectedText"])
        for item in detect_response.get("TextDetections", [])
        if item.get("Type") == "LINE" and item.get("DetectedText")
    ]


def score_name_match(lines: list[str], payload: dict[str, Any]) -> tuple[float, str]:
    profile_name = payload.get("profile_fullname") or " ".join(
        part for part in [payload.get("profile_firstname"), payload.get("profile_lastname")] if part
    )
    profile = normalized_text(profile_name or "")
    combined = normalized_text(" ".join(lines))

    if not profile or not combined:
        return 0.0, ""

    extracted_name = extract_id_name(lines)
    tokens = [token for token in profile.split(" ") if token]
    combined_tokens = set(combined.split())
    if tokens and all(token_matches_id(token, combined_tokens) for token in tokens):
        return 100.0, extracted_name or profile_name or ""

    best_line = ""
    best_score = 0.0
    for line in lines:
        score = SequenceMatcher(None, profile, normalized_text(line)).ratio() * 100
        if score > best_score:
            best_score = score
            best_line = line

    combined_score = SequenceMatcher(None, profile, combined).ratio() * 100
    if combined_score > best_score:
        best_score = combined_score
        best_line = " ".join(lines)

    return best_score, extracted_name or best_line


def verify_face_payload(payload: dict[str, Any]) -> dict[str, Any]:
    reference_image = payload.get("reference_image") or payload.get("image_reference")
    current_image = payload.get("current_snap") or payload.get("image_current")
    if not reference_image or not current_image:
        return response(
            422,
            {"detail": "Provide reference_image/current_snap or image_reference/image_current."},
        )

    try:
        reference = decode_image(reference_image)
        current = decode_image(current_image)
    except ValueError:
        return response(400, {"detail": "Invalid base64 image data."})

    try:
        compare_response = rekognition.compare_faces(
            SourceImage={"Bytes": reference},
            TargetImage={"Bytes": current},
            SimilarityThreshold=0,
        )
    except ClientError as exc:
        error_code = exc.response.get("Error", {}).get("Code")
        if error_code == "InvalidParameterException":
            return response(200, {"match": False, "message": "Face does not match."})
        return response(502, {"detail": "AWS Rekognition compare_faces failed."})
    except BotoCoreError:
        return response(502, {"detail": "AWS Rekognition compare_faces failed."})

    score = best_similarity(compare_response)
    if score >= SIMILARITY_THRESHOLD:
        rounded = round(score, 2)
        return response(200, {
            "match": True,
            "score": rounded,
            "similarity": rounded,
            "message": "Face verified successfully.",
        })

    return response(200, {"match": False, "message": "Face does not match."})


def verify_id_payload(payload: dict[str, Any]) -> dict[str, Any]:
    try:
        face_threshold = valid_threshold(payload.get("face_threshold"), ID_FACE_THRESHOLD)
        name_threshold = valid_threshold(payload.get("name_threshold"), ID_NAME_THRESHOLD)
    except (ValueError, TypeError):
        return response(422, {"detail": "Invalid verification threshold."})
    for field in ("profile_firstname", "profile_lastname", "profile_fullname"):
        value = payload.get(field)
        if value is not None and (not isinstance(value, str) or len(value) > 600):
            return response(422, {"detail": "Invalid profile name."})
    check_face = payload_bool(payload, "check_face", True)
    check_name = payload_bool(payload, "check_name", True)
    if not check_face and not check_name:
        check_face = True
        check_name = True

    if not payload.get("id_image") or (check_face and not payload.get("live_image")):
        return response(422, {"detail": "Provide id_image and live_image."})

    try:
        id_image = decode_image(payload["id_image"])
        live_image = decode_image(payload["live_image"]) if payload.get("live_image") else b""
    except ValueError:
        return response(400, {"detail": "Invalid base64 image data."})

    compare_response = {}
    text_lines = []
    try:
        if check_face:
            compare_response = rekognition.compare_faces(
                SourceImage={"Bytes": id_image},
                TargetImage={"Bytes": live_image},
                SimilarityThreshold=0,
            )
        if check_name:
            text_lines = detect_id_text(id_image)
    except ClientError as exc:
        error_code = exc.response.get("Error", {}).get("Code")
        if error_code == "InvalidParameterException":
            return response(200, {
                "verified": False,
                "status": "retry",
                "face_score": 0,
                "name_score": 0,
                "message": "Could not find a usable face or text in the ID image.",
            })
        return response(502, {"detail": "AWS Rekognition ID verification failed."})
    except BotoCoreError:
        return response(502, {"detail": "AWS Rekognition ID verification failed."})

    face_score = best_similarity(compare_response) if check_face else 100.0
    name_score, extracted_name = score_name_match(text_lines, payload) if check_name else (100.0, "")
    verified = (not check_face or face_score >= face_threshold) and (not check_name or name_score >= name_threshold)

    return response(200, {
        "verified": verified,
        "status": "pass" if verified else "failed",
        "face_score": round(face_score, 2),
        "name_score": round(name_score, 2),
        "extracted_name": extracted_name,
        "message": "ID verified." if verified else "ID verification did not pass.",
    })


def lambda_handler(event: dict[str, Any], context: Any) -> dict[str, Any]:
    if not API_KEY:
        return response(500, {"detail": "FACE_API_KEY is not configured."})

    supplied_key = get_header(event, "X-API-Key")
    if not isinstance(supplied_key, str) or not hmac.compare_digest(supplied_key.encode(), API_KEY.encode()):
        return response(401, {"detail": "Invalid API key."})

    # Function URLs/HTTP API v2 and API Gateway REST API use different method fields.
    request_context = event.get("requestContext")
    http = request_context.get("http") if isinstance(request_context, dict) else None
    method = http.get("method") if isinstance(http, dict) else None
    if not isinstance(method, str):
        method = event.get("httpMethod", "")
    if isinstance(method, str) and method.upper() == "HEAD":
        # Authenticated readiness probes never parse or transmit student evidence.
        return {"statusCode": 204, "headers": {"Cache-Control": "no-store"}, "body": ""}

    body = event.get("body") or "{}"
    if not isinstance(body, str):
        return response(400, {"detail": "Invalid JSON body."})
    if len(body) > ((MAX_REQUEST_BYTES + 2) // 3) * 4:
        return response(413, {"detail": "Request body too large."})
    try:
        if event.get("isBase64Encoded"):
            body = base64.b64decode(body, validate=True).decode("utf-8")
        if len(body.encode("utf-8")) > MAX_REQUEST_BYTES:
            return response(413, {"detail": "Request body too large."})
        payload = json.loads(body)
    except (ValueError, UnicodeError):
        return response(400, {"detail": "Invalid JSON body."})
    if not isinstance(payload, dict):
        return response(400, {"detail": "JSON body must be an object."})

    path = event.get("rawPath") or event.get("path") or ""
    if path.endswith("/verify-id"):
        return verify_id_payload(payload)

    return verify_face_payload(payload)
