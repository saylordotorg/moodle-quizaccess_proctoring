# TaView proctoring: data-flow inventory and retention schedule

Prepared for the GDPR data protection impact assessment (DPIA) requested in CPIT-472. Engineering supplies this inventory; the DPIA itself is for Legal and the DPO. Values below are the plugin's shipped defaults from 1.12.1; each site can change them under the TaView proctoring settings.

Face images and face comparisons are biometric data: special-category data under GDPR Art. 9, and covered by US state biometric laws such as BIPA.

## What is collected, where it is stored, and for how long

| Data | Where it is stored | Sent to | Kept for (default) |
| --- | --- | --- | --- |
| Webcam captures during an attempt, and the face crop from each | Moodle file storage (`picture`, `face_image` areas, quiz context); `quizaccess_proctoring_logs`, `quizaccess_proctoring_face_images` | The face-match service (AWS Lambda + Amazon Rekognition) when face matching is on | **180 days** after the attempt finishes (`imageretentiondays`), and never while a hold on the attempt is waiting for review |
| Desktop screenshots (at suspicious events, after leaving, and every 15 s while away from the quiz) | Moodle file storage (`violation_screenshot`), `quizaccess_proctoring_events` | The AI review provider, when AI review of event images is on | Same as webcam captures |
| Browser activity events (tab switches, clipboard, shortcuts, pauses, monitor count, browser and OS) | `quizaccess_proctoring_events` | — | Same as webcam captures |
| Photo ID images (front, back) and the live face photo of the ID check | Moodle file storage (`id_document`, `id_back_document`, `id_live_image`), `quizaccess_proctoring_idv` | The verification service (AWS Lambda + Amazon Rekognition: face comparison and text detection) | **30 days** after the check (`idverificationretentiondays`), but kept while a hold on that attempt is waiting for review |
| Reference photo (the student's first clear photo, or one uploaded by staff) and its face crop | Moodle file storage (`user_photo`, `face_image`, system context); `quizaccess_proctoring_user_images` | The face-match service, at each comparison | **365 days** after the student's last proctoring activity (`referenceretentiondays`); the student takes a new one at their next exam. Never while the student has a hold waiting for review |
| Precheck captures from a precheck that never led to an attempt | As webcam captures | The face-match service | **24 hours** (hourly task, CPIT-464) |
| AI review results (decision, score, model output) | `quizaccess_proctoring_ai_reviews` | — (the images were sent to the provider for the review) | Same as webcam captures |
| Risk scores, holds and reviewer decisions | `quizaccess_proctoring_risk_holds`, `quizaccess_proctoring_finding_reviews`, `quizaccess_proctoring_notes` | — | Kept as the record of the integrity decision; removed with the account or by a privacy request |
| Per-student overrides and their audit trail | `quizaccess_proctoring_overrides`, `quizaccess_proctoring_override_audit` | — | Kept while the override applies; removed with the account or by a privacy request |
| Per-attempt summaries for the SIS (no images, no ID text, no notes) | Not stored by the plugin | The Saylor SIS, when `sisexportenabled` is on | As set by the SIS |

## Processors

- **Amazon Web Services** (us-east-1): Lambda and Rekognition, for face matching and, in the ID check, face comparison and reading the name on the ID. Images are sent for each comparison and are not stored by the verification service.
- **AI review provider** (OpenAI, Anthropic, or a compatible endpoint, chosen in the settings): receives the images of an attempt or event when AI review is on. Off by default.

## Deletion

- **Retention:** the scheduled task "Delete proctoring images" applies the periods above. It now fails visibly when something goes wrong, and the settings page shows when it last finished without an error.
- **Account deletion:** deleting a Moodle account removes all of the user's proctoring records and files, the same as an approved data-privacy erasure request.
- **Privacy requests:** export and erasure through Moodle's data-privacy tool (`classes/privacy/provider.php`).

## Access

Proctoring evidence is only shown through the TaView reports, to users with the report capabilities. Student Affairs is meant to get read-only report access through a role (CPIT-474), not access to the file storage.
