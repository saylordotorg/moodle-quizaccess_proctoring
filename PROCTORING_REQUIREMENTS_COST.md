# Saylor Proctored Quiz: Bandwidth, Compatibility, Device Support, and AWS Cost Estimate

Prepared May 12, 2026. Estimates use 16,598 exam takers/month and 212,775 exam takers/year. Assumptions: one attempt per student, a typical 2-hour exam, the plugin default 30-second webcam capture interval, and the default 230px image width. Actual usage changes with quiz length, retakes, image quality, retention, and enabled monitoring options.

## Bandwidth Requirements

The plugin does not continuously upload desktop video. It uploads scheduled webcam snapshots, face/reference images, AWS face-match requests when enabled, and optional desktop screenshots only when violations occur.

Recommended student connection: HTTPS, camera permission, and at least 1 Mbps upload available during the attempt. The proctoring evidence stream is small, but Moodle page loads, question media, mobile Wi-Fi, and browser permission prompts make 1 Mbps a practical floor.

Estimated evidence upload: 50-100 KB every 30 seconds, or 12-24 MB per 2-hour attempt. At current volume, that is about 199-398 GB/month and 2.55-5.11 TB/year before retention deletion. Desktop violation screenshots add roughly 0.2-0.5 MB each when enabled.

## Compatibility and Device Support

Camera capture requires a modern browser, HTTPS, and student permission. Current Chrome, Edge, Firefox, and Safari should support webcam capture on desktop and mobile, subject to device and browser permission behavior.

Screen sharing is strongest on desktop/laptop browsers. The plugin can require entire-screen sharing, validate a screen-check challenge, monitor screen-share loss, and capture violation screenshots. Mobile/tablet screen sharing is less reliable, so the plugin defaults to bypassing desktop screen sharing on mobile/tablet while still allowing webcam proctoring.

Recommended policy: allow webcam proctoring on smartphones/tablets, but require a desktop/laptop for exams that need entire-screen sharing. With multiple monitors, the browser still makes the student choose what to share; the plugin can validate the shared surface, but Moodle cannot silently force a specific monitor.

## AWS Rekognition Cost

The AWS service used for face matching is Amazon Rekognition Image through the Saylor AI endpoint. The plugin estimate setting defaults to $0.001 per face-match check. AWS tiering may reduce the effective rate at higher volume, so this is a conservative flat-rate estimate.

| Face-match mode | Checks per 2-hour attempt | Monthly cost at 16,598 attempts | Annual cost at 212,775 attempts |
| --- | ---: | ---: | ---: |
| Start/preflight check only | 1 | $16.60 | $212.78 |
| Periodic continuous check, every 4th 30-second capture | 60 | $995.88 | $12,766.50 |
| Continuous check on every 30-second capture | 240 | $3,983.52 | $51,066.00 |

Recommendation: keep preflight face matching on, and use sampled continuous checks unless the risk level justifies checking every snapshot. Every fourth capture equals about one AWS check every 2 minutes.

## Storage Cost

The plugin stores webcam captures, face crops, desktop violation screenshots, and AI review image records in Moodle file storage. If Moodle storage is on AWS, the cost is usually EC2 EBS storage or S3 object storage, depending on Moodledata configuration. Reference face images are retained separately unless manually removed.

Storage estimate uses 12-24 MB per 2-hour attempt and excludes backups, snapshots, database growth, request charges, and data transfer out.

| Retention policy | Estimated active storage | S3 Standard at about $0.023/GB-month | EBS gp3 at about $0.08/GB-month |
| --- | ---: | ---: | ---: |
| Delete after 30 days | 199-398 GB | $5-$9/month | $16-$32/month |
| Delete after 60 days | 398-797 GB | $9-$18/month | $32-$64/month |
| Keep all annual evidence | 2.55-5.11 TB | $59-$117/month at year-end | $204-$409/month at year-end |

Overall, storage is inexpensive compared with continuous AWS face matching. The main cost control settings are quiz duration, webcam capture interval, continuous face-check frequency, desktop screenshot capture, and image retention days.
