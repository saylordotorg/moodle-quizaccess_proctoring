# Saylor Proctored Quiz: Response to Leadership Questions and Concerns

This plugin is designed to improve exam integrity without requiring a heavy lockdown-browser installation or a full redesign of existing quiz content. It adds layered evidence collection, risk scoring, review holds, certificate delay, and retake controls while keeping the student workflow browser-based.

## Technical and Implementation Concerns

| Concern | How the Plugin Addresses It |
| --- | --- |
| One question per page may cause proctoring sessions to drop during network hiccups. | The plugin does not require one-question-per-page. It keeps webcam monitoring active during the attempt and uses a persistent screen-monitor window on desktop so screen sharing does not need to be re-approved on every quiz page. |
| Scenario-based exams may have one prompt followed by multiple related questions. | Because the plugin does not depend on strict one-question-per-page delivery, grouped scenario questions can remain intact. This avoids a large-scale content review just to make proctoring work. |
| Bots may attempt assessments. | Admins can require a simple pre-attempt CAPTCHA/security check. The preferred setup is Cloudflare Turnstile, which can appear as a low-friction checkbox-style verification rather than an image puzzle. |
| AI sidebars such as Gemini or Copilot can appear inside the browser. | The plugin can require full-screen sharing, monitor tab/focus loss, log clipboard and right-click activity, detect possible AI-panel interactions, capture desktop screenshots on violations, and add these events to the attempt risk score. Browser AI panels cannot be perfectly blocked by Moodle alone, but they can be made visible as reviewable evidence when screen monitoring is enabled. |
| We need to know whether interventions actually improve integrity. | The plugin creates a measurable baseline: risk score per attempt, counts of tab switches, clipboard events, screen-share loss, face mismatch, no-face events, multiple faces, audio activity, AI-panel events, screenshots, active holds, confirmed violations, and daily summary reports. These can be compared before and after policy changes. |

## Student Experience and Accessibility

The plugin avoids the biggest access barrier of a lockdown browser: there is no one-third-gigabyte software download. Students use their normal browser, webcam, and Moodle session. This is better for global learners, students on public or shared computers, and students who may not have permission to install software in libraries, cafes, workplaces, or borrowed-device environments.

The proctoring requirements are configurable. Admins can enable or disable CAPTCHA, full-screen sharing, continuous face checks, clipboard blocking, browser monitoring, face-in-view blur, AI review, daily reports, image retention, risk-score holds, and retake lockouts. Mobile/tablet behavior is also configurable: mobile users can be allowed with webcam-only proctoring, required to attempt screen sharing, or blocked when a desktop/laptop is necessary.

The student start flow is built as a guided precheck rather than a hidden requirement. Students are shown which items must be completed before the attempt starts: integrity statement, security check, face registration or face recognition, and full-screen share when required. This reduces ambiguity while still enforcing the policy.

## Strategic and Reputational Concerns

The plugin directly addresses certificate validity by delaying grade-based certificate release for high-risk attempts. When a submitted attempt reaches the configured risk threshold, the grade is held at zero and the attempt is placed into admin review. If the hold is released, the grade is restored. If a violation is confirmed, the grade remains held and the student can be blocked from retaking the quiz for the configured number of days.

This also prevents students from "testing the waters." A high-risk attempt can block retakes even if the student failed the exam, which removes the incentive to use low-cost failed attempts to learn what the system catches.

For partners, the message is that Saylor is moving from passive trust to measured, reviewable integrity controls. The system does not claim to make cheating impossible, especially with secondary devices, but it raises the cost of dishonest behavior, preserves access for legitimate students, produces auditable evidence, and gives administrators a risk-based review process before certificates are released.

## Practical Position

The plugin is a pragmatic middle path between no proctoring and a heavy lockdown-browser requirement. It preserves broad access, avoids forcing one-question-per-page, supports mobile-friendly policies, detects common high-risk behaviors, gives administrators evidence and daily reporting, and protects certificate credibility through review holds and retake lockouts.
