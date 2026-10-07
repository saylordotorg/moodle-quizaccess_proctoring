import base64
import hmac
import os
from typing import Annotated, Any

import boto3
from botocore.exceptions import BotoCoreError, ClientError
from fastapi import FastAPI, Header, HTTPException, status
from pydantic import BaseModel, Field
from starlette.responses import JSONResponse

from name_matching import profile_names, score_name_match


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
    profile_name_variants: list[Annotated[str, Field(max_length=600)]] | None = Field(default=None, max_length=100)
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


def detect_id_text(id_image: bytes) -> list[str]:
    response = rekognition.detect_text(Image={"Bytes": id_image})
    lines: list[str] = []
    for detection in response.get("TextDetections", []):
        if detection.get("Type") == "LINE" and detection.get("DetectedText"):
            lines.append(str(detection["DetectedText"]))
    return lines


def reference_face_state(reference: bytes) -> str:
    """Whether Rekognition can find a face in the reference image: "yes", "no" or "unknown".

    compare_faces raises InvalidParameterException when either image has no face, so a
    reference saved with the face out of frame looks exactly like a mismatch. Comparing the
    reference with itself tells the two apart using the same permission. Any other failure is
    "unknown", so an outage never retires a usable reference or blames the webcam image.
    """
    try:
        rekognition.compare_faces(
            SourceImage={"Bytes": reference},
            TargetImage={"Bytes": reference},
            SimilarityThreshold=0,
        )
    except ClientError as exc:
        if exc.response.get("Error", {}).get("Code") == "InvalidParameterException":
            return "no"
        return "unknown"
    except BotoCoreError:
        return "unknown"
    return "yes"



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
            state = reference_face_state(reference)
            if state == "no":
                return {
                    "match": False,
                    "reason": "reference_no_face",
                    "message": "No face found in the reference image.",
                }
            if state == "yes":
                # The reference has a face, so the webcam image is the one without: the student
                # was not in view, which is not the same as being someone else.
                return {
                    "match": False,
                    "reason": "no_face_in_capture",
                    "message": "No face found in the webcam image.",
                }
            return {"match": False, "message": "Face does not match."}
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

    # The similarity is returned for a non-match too, so the caller can apply its own threshold.
    return {
        "match": False,
        "score": round(score, 2),
        "similarity": round(score, 2),
        "message": "Face does not match.",
    }


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
    if check_name:
        names = profile_names(
            payload.profile_fullname,
            payload.profile_firstname,
            payload.profile_lastname,
            payload.profile_name_variants,
        )
        name = score_name_match(text_lines, names)
    else:
        name = {"name_score": 100.0, "extracted_name": "", "matched_profile_name": "",
                "name_match_reason": "", "name_readable": True}
    name_score = name["name_score"]
    verified = (not check_face or face_score >= face_threshold) and (not check_name or name_score >= name_threshold)

    return {
        "verified": verified,
        "status": "pass" if verified else "failed",
        "face_score": round(face_score, 2),
        "name_score": round(name_score, 2),
        "extracted_name": name["extracted_name"],
        "matched_profile_name": name["matched_profile_name"],
        "name_match_reason": name["name_match_reason"],
        "name_readable": name["name_readable"],
        "message": "ID verified." if verified else "ID verification did not pass.",
    }


try:
    from mangum import Mangum

    handler = Mangum(app)
except ImportError:
    handler = None
