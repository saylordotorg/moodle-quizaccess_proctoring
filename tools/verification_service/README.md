# Moodle Face Verification Bridge

Optional verification service source distributed with the Moodle proctoring plugin. This directory is deployed separately from the Moodle plugin; installing or upgrading the plugin does not deploy Python code or change AWS resources. The three Python files are copied unchanged from the service validated for the 1.11.0 release.

Small FastAPI service that exposes private `/verify`, `/verify-face`, and `/verify-id` endpoints for Moodle and forwards face and ID checks to Amazon Rekognition. Face comparison accepts both payload formats:

- `{"image_reference": "...", "image_current": "..."}`
- `{"reference_image": "...", "current_snap": "..."}`

A non-match normally returns `{"match": false, "score": 72.5, "similarity": 72.5, "message": "Face does not match."}`. The similarity is included so Moodle can apply its own face-match threshold (the Moodle `threshold` setting); `FACE_SIMILARITY_THRESHOLD` only sets the `match` field.

Rekognition reports a missing face in either image the same way, so after such an error the service compares the reference with itself, using the same `rekognition:CompareFaces` permission:

- no face in the reference either: the response adds `"reason": "reference_no_face"`. Moodle records "Reference photo unusable", not a mismatch, and can let the student replace a self-registered reference;
- the reference has a face: the webcam image was the one without, and the response adds `"reason": "no_face_in_capture"`. Moodle records it as "no face", not as a mismatch;
- any other error during that second comparison: a plain non-match with no reason, so an AWS outage never retires a usable reference or blames the webcam image.

## Run

```powershell
python -m venv "$env:TEMP\proctoring-verification-venv"
& "$env:TEMP\proctoring-verification-venv\Scripts\Activate.ps1"
pip install -r requirements.txt
$env:FACE_API_KEY = python -c "import secrets; print(secrets.token_urlsafe(32))"
$env:AWS_REGION = "us-east-1"
$env:ID_FACE_THRESHOLD = "80"
$env:ID_NAME_THRESHOLD = "80"
uvicorn rekognition_bridge:app --host 127.0.0.1 --port 8000
```

Put the local service behind an HTTPS reverse proxy with a valid certificate, a 6 MiB request-body limit, and rate limiting appropriate to your exam volume. Keep port 8000 reachable only from the proxy. Moodle requires a publicly routable HTTPS endpoint; it rejects HTTP and private-network destinations. Use a long random `FACE_API_KEY` and store it in your deployment's secret configuration.

Store the generated API key in your deployment's secret store and configure the same key in Moodle. Reuse it across restarts; generate a replacement when intentionally rotating it.

Configure AWS credentials with the normal boto3 options, such as environment variables, an IAM role on EC2, or an AWS profile. The IAM role/user needs `rekognition:CompareFaces` for face matching and `rekognition:DetectText` for ID name checks.

Run these commands from this directory. Keep deployment secrets, virtual environments, ZIP packages, test output, and bytecode outside the repository. Neither bridge writes evidence to local files; request bodies contain biometric and identity data, so do not enable body logging in the proxy, application server, or AWS integrations.

The requirements pin direct dependency versions validated for this source. FastAPI and boto3 retain the versions used in the earlier security tests; Uvicorn and Mangum are separately validated optional server adapters. Transitive dependencies still resolve according to those packages' constraints. Preserve the full resolved dependency set in your deployment tooling and rerun the tests and dependency audit before updates. These requirements do not modify the managed AWS SDK in the existing one-file Lambda deployment.

## ID verification

`POST /verify-id` uses the same `X-API-Key` header. Moodle sends:

```json
{
  "id_image": "base64-id-document",
  "live_image": "base64-live-webcam-image",
  "profile_firstname": "Student",
  "profile_lastname": "Name",
  "profile_fullname": "Student Name",
  "face_threshold": 80,
  "name_threshold": 80
}
```

The service compares the ID portrait to the live face with Rekognition `compare_faces`, extracts ID text with Rekognition `detect_text`, and returns:

```json
{
  "verified": true,
  "status": "pass",
  "face_score": 91.2,
  "name_score": 100,
  "extracted_name": "Student Name",
  "message": "ID verified."
}
```

## AWS Lambda

`lambda_function.py` is a Lambda Function URL implementation with the same request and response contract. The deployed ZIP contains this file only and uses boto3 from the managed Python runtime; it does not use FastAPI, Uvicorn, or Mangum. Its handler is `lambda_function.lambda_handler`. The separate `rekognition_bridge.handler` entry point uses Mangum for deployments that choose to package the ASGI application and its dependencies.

The Lambda also accepts authenticated `HEAD` requests for Moodle's readiness checks. It returns `204` with an empty body and `Cache-Control: no-store`, without calling Rekognition. Missing or invalid API keys receive `401`. These probes confirm endpoint reachability and authentication; they do not test a face or ID comparison.

In Moodle, set:

- Face match method: `Saylor AI API`
- Saylor AI endpoint URL: `https://verification.example.org/verify-face`
- Custom API key: the same value as `FACE_API_KEY`
- Saylor ID verification endpoint URL: `https://verification.example.org/verify-id`
- ID verification API key: the same value as `FACE_API_KEY`

## Dev deployment provenance

On 2026-09-30 the bridge was deployed separately for `https://dev.sylr.org`. The original verification function was left unchanged. Keep development and production functions, aliases, permissions, and endpoint configuration separate; a plugin release is not authorization to change another environment.

| Item | Verified value |
| --- | --- |
| Region | `us-east-1` |
| Dev function | `moodle-proctoring-face-verify-dev` |
| Alias and immutable version | `dev` → `1` |
| Runtime | `python3.12` |
| ZIP contents | `lambda_function.py` only |
| Source SHA-256 | `fb52ea66b35e2352b44b3d32906b8b19cff95036e96c43ba6f4fc95e3a753876` |
| Original ZIP SHA-256 | `f472ea8d83c2d9e5c08967b1f12401caa050cb4414973e9d7cca7935fd25bbae` |
| AWS `CodeSha256` | `9HLqjYPC2eXAiWex8SQByqBQy0QUlz6dfMp5Nf0lu64=` |

At that deployment the source in this directory was checked byte for byte against the `lambda_function.py` member of the deployed archive (the source SHA-256 above). AWS `CodeSha256` is the Base64-encoded SHA-256 of the ZIP bytes, not the source file. Recreating a ZIP can change its archive hash even when the source is identical. The deployment archive and credentials are deliberately not stored here.

The checked-in source has changed since then. Later dev deployments, newest first:

| Deployed | Dev function version (alias `dev`) | Change | Source SHA-256 | AWS `CodeSha256` |
| --- | --- | --- | --- | --- |
| 2026-10-06 | `3` | CPIT-469: `reason: no_face_in_capture`; similarity returned on a non-match | `5c6b1ef23bbffc4aef0a3ddae0a8bd54f1a1ff82a4fd8cc87267aba79d9b3d99` | `7x4Z3rz6xdccxKQOCO9osdp2IwBmSpU6k+VLfkGGjko=` |
| 2026-10-02 | `2` | CPIT-453: `reason: reference_no_face` | `2fb65bad836a291eceafe44d09ca4ab446a712324458e1204934e375f16a6793` | `Hp+JemfZbzuqIx2ppmL3z6JW5vW3OXfCGcfUgt1zbaI=` |

Version 2's source was checked byte for byte against the source before CPIT-469, and version 3's ZIP holds only `lambda_function.py` from this directory. The previous version is kept for rollback (`update-alias --function-version`). The production function `moodle-proctoring-face-verify` is separate and has not been updated with these changes.

Compute the source hash from this directory:

```sh
python -B -c "import hashlib, pathlib; print(hashlib.sha256(pathlib.Path('lambda_function.py').read_bytes()).hexdigest())"
```

Compare it with the source SHA-256 recorded for the deployment you want to check.

The deployed endpoint passed authenticated readiness probes and rejected missing or invalid API keys. A successful `HEAD` probe does not validate Rekognition permissions or the result of a face or ID comparison.

## Offline security tests

Use Python 3.12 in CI to match the dev Lambda runtime. All AWS calls are mocked; these tests require no API keys, AWS credentials, live endpoints, or network access after dependencies are installed. HTTPX is a test dependency and is installed separately:

```sh
python -m pip install -r requirements.txt "httpx==0.28.1"
python -B -c "import sys, unittest; result = unittest.TextTestRunner(verbosity=2).run(unittest.defaultTestLoader.loadTestsFromName('test_security')); sys.exit(0 if result.wasSuccessful() and result.testsRun == 13 and not result.skipped else 1)"
```

The runner requires all 13 cases to execute without skips, including the FastAPI cases. Coverage includes authentication before parsing, bounded bodies/images, invalid data and thresholds, authenticated `HEAD` requests in both AWS event formats without Rekognition calls, and accepted face requests. The tests are also compatible with Python 3.14; that is the local validation runtime. The current Starlette/HTTPX combination emits a deprecation warning while those tests pass; changing the test transport is a separate dependency update.

To check known dependency advisories without applying upgrades:

```sh
python -m pip install "pip-audit==2.10.1"
python -m pip_audit -r requirements.txt
```

The resolved requirements were checked with pip-audit 2.10.1 on 2026-09-30; it reported no known vulnerabilities. Dependency audits query public advisory services and require network access. They do not establish that a package has no undiscovered vulnerabilities.
