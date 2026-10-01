import base64
from difflib import SequenceMatcher
import hmac
import os
import re
import unicodedata
from typing import Any

import boto3
from botocore.exceptions import BotoCoreError, ClientError
from fastapi import FastAPI, Header, HTTPException, status
from pydantic import BaseModel, Field
from starlette.responses import JSONResponse


API_KEY = os.getenv("FACE_API_KEY", "")
AWS_REGION = os.getenv("AWS_REGION", "us-east-1")
SIMILARITY_THRESHOLD = float(os.getenv("FACE_SIMILARITY_THRESHOLD", "80"))
ID_FACE_THRESHOLD = float(os.getenv("ID_FACE_THRESHOLD", "80"))
ID_NAME_THRESHOLD = float(os.getenv("ID_NAME_THRESHOLD", "80"))
MAX_REQUEST_BYTES = 6 * 1024 * 1024
MAX_IMAGE_BYTES = 5 * 1024 * 1024
MAX_IMAGE_TEXT = ((MAX_IMAGE_BYTES + 2) // 3) * 4 + 64

app = FastAPI(title="Moodle Face Verification Bridge")
rekognition = boto3.client("rekognition", region_name=AWS_REGION)


class VerificationRequestGuard:
    """Authenticate before parsing image payloads, and bound streamed request bodies."""

    def __init__(self, app):
        self.app = app

    async def __call__(self, scope, receive, send):
        if scope["type"] != "http" or scope.get("path") not in {"/verify", "/verify-face", "/verify-id"}:
            await self.app(scope, receive, send)
            return
        if not API_KEY:
            await JSONResponse({"detail": "FACE_API_KEY is not configured."}, status_code=500)(scope, receive, send)
            return
        headers = dict(scope.get("headers", []))
        if not hmac.compare_digest(headers.get(b"x-api-key", b""), API_KEY.encode()):
            await JSONResponse({"detail": "Invalid API key."}, status_code=401)(scope, receive, send)
            return

        received = 0

        async def bounded_receive():
            nonlocal received
            message = await receive()
            if message["type"] == "http.request":
                received += len(message.get("body", b""))
                if received > MAX_REQUEST_BYTES:
                    raise HTTPException(status_code=413, detail="Request body too large.")
            return message

        await self.app(scope, bounded_receive, send)


app.add_middleware(VerificationRequestGuard)

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


class VerifyRequest(BaseModel):
    image_reference: str | None = Field(default=None, max_length=MAX_IMAGE_TEXT)
    image_current: str | None = Field(default=None, max_length=MAX_IMAGE_TEXT)
    reference_image: str | None = Field(default=None, max_length=MAX_IMAGE_TEXT)
    current_snap: str | None = Field(default=None, max_length=MAX_IMAGE_TEXT)


class VerifyIdRequest(BaseModel):
    id_image: str = Field(min_length=1, max_length=MAX_IMAGE_TEXT)
    live_image: str | None = Field(default=None, max_length=MAX_IMAGE_TEXT)
    profile_firstname: str | None = Field(default=None, max_length=600)
    profile_lastname: str | None = Field(default=None, max_length=600)
    profile_fullname: str | None = Field(default=None, max_length=600)
    face_threshold: float | None = Field(default=None, gt=0, le=100, allow_inf_nan=False)
    name_threshold: float | None = Field(default=None, gt=0, le=100, allow_inf_nan=False)
    check_face: bool | None = None
    check_name: bool | None = None


def get_images(payload: VerifyRequest) -> tuple[str, str]:
    reference = payload.image_reference or payload.reference_image
    current = payload.image_current or payload.current_snap

    if not reference or not current:
        raise HTTPException(
            status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
            detail="Provide image_reference/image_current or reference_image/current_snap.",
        )

    return reference, current


def decode_image(image_data: str) -> bytes:
    if not isinstance(image_data, str) or not image_data or len(image_data) > MAX_IMAGE_TEXT:
        raise HTTPException(status_code=400, detail="Invalid image data.")
    if "," in image_data:
        image_data = image_data.split(",", 1)[1]

    try:
        decoded = base64.b64decode(image_data, validate=True)
        if not decoded or len(decoded) > MAX_IMAGE_BYTES:
            raise ValueError("Invalid image size.")
        return decoded
    except ValueError as exc:
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail="Invalid base64 image data.",
        ) from exc


def best_similarity(response: dict[str, Any]) -> float:
    matches = response.get("FaceMatches", [])
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
    response = rekognition.detect_text(Image={"Bytes": id_image})
    lines: list[str] = []
    for detection in response.get("TextDetections", []):
        if detection.get("Type") == "LINE" and detection.get("DetectedText"):
            lines.append(str(detection["DetectedText"]))
    return lines


def score_name_match(lines: list[str], payload: VerifyIdRequest) -> tuple[float, str]:
    profile_name = payload.profile_fullname or " ".join(
        part for part in [payload.profile_firstname, payload.profile_lastname] if part
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
        normalized_line = normalized_text(line)
        score = SequenceMatcher(None, profile, normalized_line).ratio() * 100
        if score > best_score:
            best_score = score
            best_line = line

    combined_score = SequenceMatcher(None, profile, combined).ratio() * 100
    if combined_score > best_score:
        best_score = combined_score
        best_line = " ".join(lines)

    return best_score, extracted_name or best_line


@app.post("/verify")
@app.post("/verify-face")
def verify_faces(payload: VerifyRequest, x_api_key: str = Header(default="", alias="X-API-Key")):
    if not API_KEY:
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail="FACE_API_KEY is not configured.",
        )

    if not hmac.compare_digest(x_api_key.encode(), API_KEY.encode()):
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Invalid API key.",
        )

    reference_image, current_image = get_images(payload)
    reference = decode_image(reference_image)
    current = decode_image(current_image)

    try:
        response = rekognition.compare_faces(
            SourceImage={"Bytes": reference},
            TargetImage={"Bytes": current},
            SimilarityThreshold=0,
        )
    except ClientError as exc:
        error_code = exc.response.get("Error", {}).get("Code")
        if error_code == "InvalidParameterException":
            return {"match": False}
        raise HTTPException(
            status_code=status.HTTP_502_BAD_GATEWAY,
            detail="AWS Rekognition compare_faces failed.",
        ) from exc
    except BotoCoreError as exc:
        raise HTTPException(
            status_code=status.HTTP_502_BAD_GATEWAY,
            detail="AWS Rekognition compare_faces failed.",
        ) from exc

    score = best_similarity(response)
    if score >= SIMILARITY_THRESHOLD:
        return {
            "match": True,
            "score": round(score, 2),
            "similarity": round(score, 2),
            "message": "Face verified successfully.",
        }

    return {"match": False, "message": "Face does not match."}


@app.post("/verify-id")
def verify_id(payload: VerifyIdRequest, x_api_key: str = Header(default="", alias="X-API-Key")):
    if not API_KEY:
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail="FACE_API_KEY is not configured.",
        )

    if not hmac.compare_digest(x_api_key.encode(), API_KEY.encode()):
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Invalid API key.",
        )

    check_face = payload.check_face is not False
    check_name = payload.check_name is not False
    if not check_face and not check_name:
        check_face = True
        check_name = True

    id_image = decode_image(payload.id_image)
    if check_face and not payload.live_image:
        raise HTTPException(
            status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
            detail="Provide id_image and live_image.",
        )

    live_image = decode_image(payload.live_image) if payload.live_image else b""
    face_threshold = payload.face_threshold or ID_FACE_THRESHOLD
    name_threshold = payload.name_threshold or ID_NAME_THRESHOLD

    compare_response: dict[str, Any] = {}
    text_lines: list[str] = []
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
            return {
                "verified": False,
                "status": "retry",
                "face_score": 0,
                "name_score": 0,
                "message": "Could not find a usable face or text in the ID image.",
            }
        raise HTTPException(
            status_code=status.HTTP_502_BAD_GATEWAY,
            detail="AWS Rekognition ID verification failed.",
        ) from exc
    except BotoCoreError as exc:
        raise HTTPException(
            status_code=status.HTTP_502_BAD_GATEWAY,
            detail="AWS Rekognition ID verification failed.",
        ) from exc

    face_score = best_similarity(compare_response) if check_face else 100.0
    name_score, extracted_name = score_name_match(text_lines, payload) if check_name else (100.0, "")
    verified = (not check_face or face_score >= face_threshold) and (not check_name or name_score >= name_threshold)

    return {
        "verified": verified,
        "status": "pass" if verified else "failed",
        "face_score": round(face_score, 2),
        "name_score": round(name_score, 2),
        "extracted_name": extracted_name,
        "message": "ID verified." if verified else "ID verification did not pass.",
    }


try:
    from mangum import Mangum

    handler = Mangum(app)
except ImportError:
    handler = None
