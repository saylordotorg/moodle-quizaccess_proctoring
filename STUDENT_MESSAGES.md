# Messages TaView shows students

Every student-facing string in the plugin, grouped by where the student sees it (CPIT-481). Student Affairs can review the wording here. A string can be changed without a code release through Site administration > Language > Language customisation, component `quizaccess_proctoring`.

Generated from `lang/en/quizaccess_proctoring.php`; `{$a}` and `{$a->...}` are filled in when the message is shown.

## Before the exam: setup steps

| String key | Text |
| --- | --- |
| `cameradark` | Your camera is on, but it is sending a completely black picture. Check that nothing is covering the camera (a privacy shutter or slider), that a camera-off key on your keyboard is not switched on, that your computer's camera privacy settings allow this browser to use the camera, and that no other app (such as Zoom or Teams) is using it. Then try again. |
| `devicenotice:handheld` | This looks like a phone or tablet. A proctored exam needs a laptop or desktop computer with a webcam: setup asks you to share your whole screen, which phones and tablets cannot do. Switch devices before you start — the exam timer has not begun. |
| `devicenotice:nocamera` | This browser cannot reach a camera, so identity setup will not be able to run. A proctored exam needs a laptop or desktop computer with a working webcam. If you have one, check that no other application is using it and that the browser is allowed to use the camera, then reload this page. The exam timer has not begun. |
| `facematched` | Face matched. |
| `facenotfoundoncam` | Face not found. Try changing your camera to a better lighting. Thanks. |
| `facenotmatched` | Face not matched. |
| `facequalityfailed` | Make sure your face is centered, well lit, and in focus, then try again. |
| `faceregistered` | Face registered. You can now start the quiz. |
| `identityrecheck:expired` | Your previous photo ID check has expired. Verify your photo ID again before starting. |
| `identityrecheck:missing` | Complete ID verification before starting this attempt. |
| `identityrecheck:namechanged` | Your profile name has changed or your earlier check has no saved name record. Verify your photo ID again before starting. |
| `identityrecheck:newattempt` | This quiz requires a fresh photo ID check for each new attempt. Verify your photo ID and start within ten minutes. |
| `identityrecheck:policychanged` | The identity requirements have changed or your earlier check has no saved policy record. Verify your photo ID again before starting. |
| `idrecheckrequired` | Your photo ID verification is no longer current. Return to the preflight check and verify your photo ID again. |
| `invalidapi` | Face match API key is invalid. Please contact the admin. |
| `modal:checkmonitors` | Check monitor setup |
| `modal:faceregistration` | Face registration: |
| `modal:facevalidation` | Face validated: |
| `modal:faceverificationrequired` | Complete the face check before starting the quiz. If it has expired, run it again. |
| `modal:idexemptionalready` | Already noted for this exam — you do not need to do this again. Make sure you have emailed student support the details above. |
| `modal:idexemptionbutton` | I can't provide a photo ID |
| `modal:idexemptionincomplete` | Choose a reason and explain your situation before submitting — a request without those details cannot be reviewed. |
| `modal:idexemptionrecorded` | Noted on your exam record so student support can find your request. Send the email above so they have your details. |
| `modal:idexemptionunavailable` | This option is not available right now. Please contact student support directly. |
| `modal:idverification` | ID verification: |
| `modal:idverificationdocument` | ID image |
| `modal:idverificationdocumentback` | ID back image |
| `modal:idverificationdocumentbackmissing` | Upload a clear image of the back of your photo ID first. |
| `modal:idverificationdocumentblurry` | The captured image looked blurry. Hold the ID steady, improve the lighting or move it slightly further from the camera, then capture again. |
| `modal:idverificationdocumentcamera` | Start ID camera |
| `modal:idverificationdocumentcapture` | Capture ID image |
| `modal:idverificationdocumentfront` | ID front image |
| `modal:idverificationdocumentinwindow` | ID in window - hold still |
| `modal:idverificationdocumentmissing` | Upload a clear image of your photo ID first. |
| `modal:idverificationdocumentnotinwindow` | ID not in window |
| `modal:idverificationdocumentready` | ID in window - click Capture |
| `modal:idverificationdocumentretake` | Retake ID image |
| `modal:idverificationfailed` | Photo ID verification did not pass. Make sure your photo ID is readable and your face is visible, then try again. |
| `modal:idverificationfailed_both` | ID verification did not pass because the face and name checks did not match closely enough. Make sure the ID is readable, your face is clearly visible, and the ID name matches your Moodle profile name. |
| `modal:idverificationfailed_face` | ID verification did not pass because your live face did not match the ID photo closely enough. Make sure your face and the ID photo are well lit, clear, and unobstructed, then try again. |
| `modal:idverificationfailed_name` | ID verification did not pass because the ID name did not match your Moodle profile name closely enough. ID name read: {$a->idname}. Moodle profile name: {$a->profilename}. Use an ID that matches your Moodle profile, or update your Moodle profile name if it is incorrect. |
| `modal:idverificationfailed_name_multilingual` | ID verification did not pass because the ID name did not match your Moodle profile name closely enough. ID name read: {$a->idname}. Romanized name: {$a->romanizedname}. Moodle profile name: {$a->profilename}. Match details: {$a->reason}. |
| `modal:idverificationimagetoolarge` | Your ID photos are too large to check. Retake the ID picture a little further back so the document fills the guide without extra space around it, and try again. If it keeps happening, email student support - this is a limit on our side, not a problem with your ID. |
| `modal:idverificationnameunknown` | No name was read from the ID |
| `modal:idverificationpassed` | ID verified. |
| `modal:idverificationprovidererror` | ID verification is unavailable. Please contact support. |
| `modal:idverificationreasonunknown` | No name match details were returned |
| `modal:idverificationretry` | ID verification needs a clearer ID and live face image. |
| `modal:idverificationverify` | Verify ID |
| `modal:pending` | Pending |
| `modal:registerface` | Register face |
| `modal:screenshare` | Screen sharing: |
| `modal:shareentirescreen` | Share entire screen |
| `modal:validateface` | Validate face recognition |
| `photonotuploaded` | Photo not uploaded. Please contact to the admin. |
| `preflight:actionneeded` | Action needed |
| `preflight:back` | Back |
| `preflight:cancelwithdraw` | Leave and withdraw consent |
| `preflight:cancelwithdrawtitle` | Leaves the exam setup without starting. Webcam photos from setup are deleted within 24 hours. A photo ID check, if you completed one, is kept for the period stated in the privacy notice. |
| `preflight:complete` | Complete |
| `preflight:continue` | Continue |
| `preflight:facerecognition` | Pass face recognition |
| `preflight:honesty` | Check the honesty statement |
| `preflight:idverification` | Verify your photo ID |
| `preflight:privacy` | Acknowledge the privacy notice |
| `preflight:progresscount` | {$a->done} of {$a->total} complete |
| `preflight:registerface` | Register your face |
| `preflight:requirementsheading` | Setup checklist |
| `preflight:requirementsintro` | Complete each required item below. The Start attempt button unlocks after every item is complete. |
| `preflight:screenshare` | Share your entire screen |
| `preflight:securitycheck` | Pass the security check |
| `preflight:servererrors` | Review these errors before starting the quiz: |
| `preflight:setupcomplete` | Setup complete — ready to start |
| `preflight:singlemonitor` | Use one monitor |
| `preflight:stepcounter` | Step {$a->current} of {$a->total} |
| `preflight:submitlocked` | Complete precheck first |
| `preflight:timelimitfooter` | Time limit: {$a} — cannot be paused |
| `preflight:viewhonor` | Honesty statement |
| `preflight:viewprivacy` | Privacy notice |
| `preflightstep:captcha:desc` | Complete the checkmark security step to continue. |
| `preflightstep:captcha:title` | Security check |
| `preflightstep:face:desc` | Keep your face centered in the camera and verify your face. |
| `preflightstep:face:title` | Face recognition |
| `preflightstep:honor:desc` | Read the statement and check the agreement box. |
| `preflightstep:honor:title` | Honesty statement |
| `preflightstep:idverification:definition` | Your photo ID should include your full name and a photograph, such as a passport, driver's license, government-issued ID, work-issued ID, or school-issued ID. |
| `preflightstep:idverification:desc` | Upload a clear image of your photo ID, then capture your live face for comparison. |
| `preflightstep:idverification:title` | Photo ID verification |
| `preflightstep:multimonitor:desc` | Use a single display during the quiz. Supported browsers can detect when more than one monitor is active. |
| `preflightstep:multimonitor:title` | Monitor setup |
| `preflightstep:privacy:desc` | Review the short data notice and check the acknowledgement box. |
| `preflightstep:privacy:title` | Privacy notice |
| `preflightstep:ready` | Precheck complete. You can start the attempt. |
| `preflightstep:registerface:desc` | Keep your face centered in the camera and save your first reference photo. |
| `preflightstep:registerface:title` | Register your face |
| `preflightstep:screen:desc` | Share the entire screen that contains this quiz page. |
| `preflightstep:screen:title` | Screen sharing |
| `privacynotice:agreementdefault` | I understand what proctoring data may be collected for this quiz. |
| `privacynotice:default` | This proctored quiz may collect limited data needed to verify identity, protect exam integrity, and support review of flagged attempts. On your first proctored quiz, the first clear photo from the face check is kept as your reference photo, so later quizzes can confirm it is you. Photos from a check you do not finish are deleted within a day. |
| `privacynotice:detailsummary` | View data collected for this quiz |
| `privacynotice:item_aireview` | AI-assisted review results for flagged images or screenshots. |
| `privacynotice:item_browseractivity` | Browser focus, tab visibility, and page-exit activity during the attempt. |
| `privacynotice:item_captcha` | Security-check completion status before the attempt starts. |
| `privacynotice:item_clipboard` | Copy, cut, paste, and right-click activity. |
| `privacynotice:item_desktop` | Screen-share status and desktop screenshots when suspicious activity occurs, and repeatedly while you are away from the quiz. While you are away these show whatever is on your screen, which can include personal content, so close anything private before the exam. |
| `privacynotice:item_eventlogs` | Proctoring event logs, timestamps, risk score, and reviewer actions. |
| `privacynotice:item_facepause` | Pausing the quiz while no face is in view of your webcam, checked in your browser; each pause is logged with how long it lasted and the webcam frame from when it began. |
| `privacynotice:item_idverification` | Government or institutional ID image, back ID image when required, live selfie image, extracted ID name, and identity verification result when ID verification is required. |
| `privacynotice:item_monitors` | Multi-monitor detection status when supported by the browser. |
| `privacynotice:item_mouse` | Desktop mouse or pointer leave and return activity during the attempt. |
| `privacynotice:item_multiplefaces` | Automatic counting of the faces in your webcam image during the attempt, done in your browser, to flag another person who stays in view; the flagged webcam frame is stored as evidence for a person to review. |
| `privacynotice:item_periodicscreen` | Periodic whole-screen screenshots to show when screen evidence is present or missing. These images are stored for authorized human review under the evidence retention policy. |
| `privacynotice:item_phonedetection` | Automatic object detection on webcam images to flag a visible mobile phone, with the flagged webcam frame stored as evidence. |
| `privacynotice:item_riskreview` | High-risk review status, automatic-failure or grade/certificate hold status, and retake lockout status when configured. |
| `privacynotice:item_webcam` | Webcam images, reference face image, face match status, and face quality checks. |
| `privacynotice:required` | You must acknowledge the proctoring privacy notice before starting the quiz. |
| `privacynotice:retentiondays` | Captured proctoring images are normally deleted {$a} days after the exam unless needed for review, appeal, legal, or security reasons. |
| `privacynotice:retentionid` | Photo ID images are deleted {$a} days after the check, unless a hold on that exam is still waiting for review. |
| `privacynotice:retentionmanual` | Captured proctoring images are retained until manual deletion unless needed for review, appeal, legal, or security reasons. |
| `privacynotice:retentiononeday` | Captured proctoring images are normally deleted 1 day after the exam unless needed for review, appeal, legal, or security reasons. |
| `privacynotice:retentionreference` | Your reference photo is deleted after {$a} days without a proctored exam, and you take a new one at your next exam. Your proctoring data is also deleted if your account is deleted. |
| `referenceconfirm:imagealt` | Your new reference photo |
| `referenceconfirm:intro` | This will be your reference photo. On your proctored exams, your webcam photos are compared with it to confirm it is you. It is kept for {$a} days after your last proctored exam. Check that your face is clear and well lit, then use this photo or take another one. |
| `referenceconfirm:intronolimit` | This will be your reference photo. On your proctored exams, your webcam photos are compared with it to confirm it is you. Check that your face is clear and well lit, then use this photo or take another one. |
| `referenceconfirm:retake` | Take another photo |
| `referenceconfirm:use` | Use this photo |
| `referencecountdown` | Taking your photo in |
| `referencereset` | Your saved reference photo did not show your face clearly enough to be used, so it has been cleared. Center your face in the camera and select the button again to save a new reference photo. |
| `referencereset:busy` | The reference photo is being changed right now. Try again in a moment. |
| `referencereset:button` | Reset reference photo |
| `referencereset:done` | The reference photo was reset. The student takes a new one at their next proctored exam. |
| `referencereset:reason` | Reason (recorded in the logs) |
| `referencereset:reasonrequired` | Enter a reason to reset the reference photo. |
| `referencereset:submit` | Reset the photo |
| `referenceunusable` | Your saved reference photo does not show a face, so it cannot be matched. Contact support and ask for your proctoring reference photo to be replaced. |
| `screenmarkerchecking` | Checking the shared screen. Keep this quiz window in front so the screen check marker stays visible. |
| `screenmarkerlabel` | Screen check |
| `screenmarkerwrongmonitor` | The shared screen does not show this quiz page. Move the quiz window to the shared monitor or share the monitor containing the quiz. |
| `screenshareaccepted` | Entire screen shared. You can continue. |
| `screensharedenied` | Screen sharing was cancelled or blocked. Share your entire screen to continue. |
| `screensharenotsupported` | This browser does not support screen sharing. Use a supported browser such as Chrome or Edge. |
| `screensharestopped` | Screen sharing stopped. Share your entire screen again to continue. |

## Photo ID exception requests

| String key | Text |
| --- | --- |
| `idexemption:allexamslink` | See pending requests for every exam |
| `idexemption:altlabel` | What documentation can you provide instead? (optional) |
| `idexemption:altplaceholder` | For example a student ID, a refugee or asylum document, or a letter from an institution. |
| `idexemption:capture_heading` | Tips for a usable ID picture |
| `idexemption:capture_maildevice` | Device and browser: |
| `idexemption:capture_mailsubject` | Trouble capturing my ID — {$a} |
| `idexemption:capture_mailwhat` | What happens when I try to capture my ID: |
| `idexemption:capture_stuck` | I tried these and still can't get a usable picture |
| `idexemption:capture_stuckintro` | Email {$a} with the type of ID you are using, the device and browser you are on, and what happens when you try to capture it. A screenshot or a photo of your screen helps. |
| `idexemption:capture_tip1` | Take the ID out of any plastic sleeve or wallet window — the plastic reflects light back at the camera. |
| `idexemption:capture_tip2` | Lay it flat on a dark, plain surface instead of holding it in your hand. |
| `idexemption:capture_tip3` | Use bright, even light, but keep the light behind you or to one side so it does not glare off the ID. |
| `idexemption:capture_tip4` | Fill the frame with the ID: all four corners visible, and no fingers over the photo or the text. |
| `idexemption:capture_tip5` | Wipe the camera lens, hold steady, and give the camera a moment to focus before you capture. |
| `idexemption:capture_tip6` | If your webcam is low quality, open this exam page on your phone and use its back camera instead. |
| `idexemption:category_displaced` | I am a refugee, asylum seeker, or displaced person without identity documents |
| `idexemption:category_expired` | My ID has expired and I cannot renew it right now |
| `idexemption:category_lostorstolen` | My ID was lost, stolen, or damaged and I do not have a replacement yet |
| `idexemption:category_never` | I have never been issued a government or institutional photo ID |
| `idexemption:category_other` | Another reason (explain below) |
| `idexemption:category_withheld` | My ID is being held by an employer, sponsor, or authority |
| `idexemption:categorylabel` | Why can't you provide a photo ID? |
| `idexemption:categoryprompt` | Choose the closest match... |
| `idexemption:detaillabel` | Tell us more, in your own words |
| `idexemption:detailplaceholder` | What have you tried, and what is stopping you from getting a photo ID? The more detail you give here, the less we need to ask you afterwards. |
| `idexemption:mail_course` | Course: {$a} |
| `idexemption:mail_email` | Account email: {$a} |
| `idexemption:mail_exam` | Exam: {$a} |
| `idexemption:mail_fullname` | Full name: {$a} |
| `idexemption:mailhint` | That link opens a draft in your email app with these details already filled in — nothing is sent until you send it. You can also copy the address into your own email instead. |
| `idexemption:nodetail` | No explanation was recorded with this request. |
| `idexemption:noid_emailintro` | Your request is with student support. Please also email it to them so it reaches their support queue — this draft already contains what you wrote. |
| `idexemption:noid_intro` | Exceptions to photo ID verification are reviewed one at a time by a person. Tell us why you cannot provide one, and student support will decide from what you write here. |
| `idexemption:noid_mailalt` | Other documentation I can provide instead: |
| `idexemption:noid_mailreason` | Why I cannot provide a government or institutional photo ID: |
| `idexemption:noid_mailsubject` | ID exception request — {$a} |
| `idexemption:noid_wait` | Do not start the exam until you hear back. |
| `idexemption:nonepending` | No exception requests are waiting for a decision. |
| `idexemption:notnow_body` | Nothing has started yet — your attempt and its timer only begin after you finish these setup steps. Fetch your photo ID, come back to this page while the exam is still open, and carry on from here. There is nothing to send us. |
| `idexemption:reason_capture` | I can't get a usable picture of my ID |
| `idexemption:reason_noid` | I don't have a photo ID |
| `idexemption:reason_notnow` | I have an ID, but I don't have it with me right now |
| `idexemption:reasonlabel_capture` | Cannot capture a usable ID picture |
| `idexemption:reasonlabel_noid` | Has no photo ID (reason not recorded) |
| `idexemption:reasonlabel_unknown` | Not stated |
| `idexemption:requiredhint` | Choose a reason and explain your situation before submitting. |
| `idexemption:submitrequest` | Submit request |
| `idexemption:triageheading` | Which of these describes your situation? |
| `idexemption:turnaround` | Student support replies to the email you send, normally within 1-2 business days (Monday to Friday). |

## During the exam: warnings

| String key | Text |
| --- | --- |
| `attemptwarning:multiplemonitors` | Multiple monitors were detected. Use one monitor during this quiz. |
| `attemptwarning:quiznotinview` | The quiz window lost focus. Keep the quiz visible and active during the attempt. |
| `attemptwarning:screensharestopped` | Screen sharing stopped. Share your entire screen again to continue. |
| `attemptwarning:sessionlost` | Your Moodle login session has changed or expired, so exam monitoring has stopped recording. Do not close this exam. Open a new browser tab, log in to Moodle again with your own account, then return here and reload the page to continue. |
| `attemptwarning:title` | Saylor proctoring notice |
| `attemptwarning:wrongscreen` | The shared screen does not show this quiz page. Share the monitor containing the quiz and screen check marker. |
| `faceblurmessage` | Keep your face in view to continue the quiz. |
| `info:cameraallow` | Your camera is now in use. |
| `warning:cameraallowwarning` | Please allow camera access. |

## Emails and notifications to students

| String key | Text |
| --- | --- |
| `idexemptionemail:approved:cta` | Go to the exam |
| `idexemptionemail:approved:eyebrow` | Proctoring · Approved |
| `idexemptionemail:approved:intro` | Student support approved your request. You can now take {$a} without the ID verification step — every other exam check still applies. |
| `idexemptionemail:approved:subject` | Your ID exception was approved — {$a} |
| `idexemptionemail:approved:title` | Your ID exception was approved |
| `idexemptionemail:declined:eyebrow` | Proctoring · Update |
| `idexemptionemail:declined:intro` | Student support reviewed your request for {$a->quiz} and could not approve it. If you have questions or new information to share, contact {$a->contact}. |
| `idexemptionemail:declined:subject` | Update on your ID exception request — {$a} |
| `idexemptionemail:declined:title` | Your ID exception request was not approved |
| `idexemptionemail:footer` | This is an automated notification from {$a} proctoring — please do not reply. |
| `idexemptionemail:footerreplyto` | This is an automated notification from {$a} proctoring — replies go to student support. |
| `idexemptionemail:labelcourse` | Course |
| `idexemptionemail:labelexam` | Exam |
| `idexemptionemail:labelrequested` | Requested |
| `idexemptionemail:replytoname` | Student support |
| `idexemptionemail:timeformat` | %d %B %Y, %I:%M %p %Z |
| `message:autofailedbody` | Your attempt for "{$a->quiz}" in {$a->course} reached the configured high-risk threshold and was automatically failed. Your attempt grade has been set to zero. If you have questions, please contact your instructor. |
| `message:autofailedsubject` | Proctored quiz attempt automatically failed |
| `message:confirmedbody` | Your attempt for "{$a->quiz}" in {$a->course} has been reviewed. A proctoring violation was confirmed, and your grade for this attempt has been set to zero. If you have questions, please contact your instructor. |
| `message:confirmedsubject` | Proctored quiz review complete: violation confirmed |
| `message:releasedbody` | Your attempt for "{$a->quiz}" in {$a->course} has been reviewed. The hold on your grade has been released, so your grade for this attempt is now available. |
| `message:releasedsubject` | Proctored quiz review complete: hold released |

## My proctoring photo page

| String key | Text |
| --- | --- |
| `myphoto:intro` | This is the reference photo your proctored exams compare your webcam with, to confirm it is you. |
| `myphoto:none` | You have no reference photo on file. You take one at the start of your first proctored exam. |
| `myphoto:reason` | What is wrong with the photo? (optional) |
| `myphoto:requestbutton` | Ask for a new photo |
| `myphoto:requestheading` | Need a new photo? |
| `myphoto:requestintro` | If this photo is unclear or is not of you, ask Student Affairs to reset it. You then take a new one at your next proctored exam. |
| `myphoto:requestpending` | Your request has been sent to Student Affairs. You can send another one tomorrow. |
| `myphoto:requestsent` | Your request has been sent to Student Affairs. |
| `myphoto:title` | My proctoring photo |
