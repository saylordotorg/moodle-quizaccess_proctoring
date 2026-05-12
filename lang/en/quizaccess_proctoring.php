<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Strings for the quizaccess_proctoring plugin.
 *
 * @package    quizaccess_proctoring
 * @copyright  2020 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();
$string['accessdenied'] = 'Access Denied';
$string['action_upload_image'] = 'Action';
$string['actions'] = 'Actions';
$string['additional_settings'] = 'General settings';
$string['analyzbtn'] = 'Analyze';
$string['analyzbtnconfirm'] = 'Click the Analyze button for face match of the user.';
$string['analyzimage'] = 'Analyze images';
$string['areyousure_delete_all_course_record'] = 'Are you sure you want to delete all images and records of students that were captured during the exams for <b>this course?</b>';
$string['areyousure_delete_all_record'] = 'Are you sure you want to delete all images of students that were captured during the exams?';
$string['areyousure_delete_image'] = 'Do you want to delete this image?';
$string['areyousure_delete_record'] = 'Are you sure you want to delete this record?';
$string['back'] = 'Back';
$string['cancel_image_upload'] = 'Cancelled image upload';
$string['confirmdeletioncourse'] = 'Are you sure you want to delete this course pictures?';
$string['confirmdeletionquiz'] = 'Are you sure you want to delete the pictures of this quiz?';
$string['course_proctoring_summary'] = 'Course Report';
$string['costestimate'] = 'Proctoring AI cost estimate';
$string['costestimate:capturesperstudent'] = 'Webcam captures per student';
$string['costestimate:continuouschecks'] = 'Continuous checks per student';
$string['costestimate:durationminutes'] = 'Quiz duration (minutes)';
$string['costestimate:estimatedtotal'] = 'Estimated total quiz cost';
$string['costestimate:formheading'] = 'Estimate Rekognition face-match cost';
$string['costestimate:includepreflight'] = 'Include one preflight face validation per student';
$string['costestimate:intervalseconds'] = 'Webcam capture interval (seconds)';
$string['costestimate:note'] = 'This estimate is for API face-match checks only. First-time self-registration stores the reference image and does not call the face-match API until validation or continuous checks run.';
$string['costestimate:perstudent'] = 'Estimated cost per student';
$string['costestimate:preflightchecks'] = 'Preflight checks per student';
$string['costestimate:recalculate'] = 'Recalculate estimate';
$string['costestimate:students'] = 'Number of students';
$string['costestimate:totalchecks'] = 'Total face-match checks';
$string['costestimate:unitcost'] = 'Cost per face-match check';
$string['dateverified'] = 'Date and time';
$string['delete'] = 'Delete';
$string['delete_images_task'] = 'Delete images task';
$string['delete_images_task_desc'] = 'Delete all proctoring images';
$string['deleteallcourse'] = 'Delete course images';
$string['deletequizdata'] = 'Delete quiz images';
$string['desktopcapture'] = 'Desktop capture';
$string['desktopcaptureprompt'] = 'Share the entire screen that shows this quiz page and its screen check marker.';
$string['desktopcapturetitle'] = 'Desktop capture required';
$string['email']  = 'Email address';
$string['enable_web_camera_before_submitting'] = 'You need to enable web camera before submitting this quiz!';
$string['eprotroringreports'] = 'Proctoring report for: ';
$string['eprotroringreportsdesc'] = 'In this report you will find all the images of the students which are taken during the exam. Now you can validate their identity, like their profile picture and webcam images.';
$string['error_face_not_found'] = 'Face not found in the image. Please contact the administrator.';
$string['error_invalid_report'] = 'Invalid report data. Please try again.';
$string['examdata'] = 'No data is available for this exam session. Please check the exam setup or monitoring configurations.';
$string['entirescreenrequired'] = 'Select your entire screen, not a browser tab or application window, to continue.';
$string['execute_facematch_task'] = 'Execute face match task';
$string['eventdetails'] = 'Details';
$string['eventtype'] = 'Event';
$string['eventtype:clipboard_copy'] = 'Copied content';
$string['eventtype:clipboard_cut'] = 'Cut content';
$string['eventtype:clipboard_paste'] = 'Pasted content';
$string['eventtype:contextmenu'] = 'Opened context menu';
$string['eventtype:focus_lost'] = 'Left quiz window';
$string['eventtype:focus_returned'] = 'Returned to quiz window';
$string['eventtype:page_exit'] = 'Left quiz page';
$string['eventtype:possible_ai_tool'] = 'Possible AI tool interaction';
$string['eventtype:screen_marker_missing'] = 'Wrong screen shared';
$string['eventtype:screen_share_stopped'] = 'Screen sharing stopped';
$string['eventtype:shortcut'] = 'Monitored keyboard shortcut';
$string['eventtype:tab_hidden'] = 'Quiz tab hidden';
$string['eventtype:tab_visible'] = 'Quiz tab visible again';
$string['facefound'] = 'Face found in the uploaded image.';
$string['facematch'] = 'Face match successful. The student identity is verified.';
$string['facematched'] = 'Face matched.';
$string['facematchs'] = 'All images have been successfully analyzed. Please review them to verify the face match.';
$string['faceregistered'] = 'Face registered. You can now start the quiz.';
$string['facequalityfailed'] = 'Make sure your face is centered, well lit, and in focus, then try again.';
$string['facenotfound'] = 'Face not found in the uploaded image.';
$string['facenotfoundoncam'] = 'Face not found. Try changing your camera to a better lighting. Thanks.';
$string['facenotmatched'] = 'Face not matched.';
$string['foundtext'] = 'Found';
$string['identity_mismatch_label'] = 'Identity Mismatch';
$string['image'] = 'Upload Image';
$string['image_not_uploaded'] = 'The uploaded image does not contain any faces.';
$string['image_updated'] = 'Image updated';
$string['image_upload'] = 'Upload image';
$string['info:cameraallow'] = 'Your camera is now in use.';
$string['initiate_facematch_task'] = 'Initiate face match task';
$string['initiate_facematch_task_desc'] = 'Initiates a face match task to compare images for proctoring verification.';
$string['invalid_api'] = 'The configured custom AI API key is invalid.';
$string['invalid_facematch_method'] = 'Invalid face match method or missing face match API credentials in settings.';
$string['invalid_service_api'] = 'The configured face match service API is invalid.';
$string['invalidapi'] = 'Face match API key is invalid. Please contact the admin.';
$string['invalidsesskey'] = 'Invalid session key. Please try again.';
$string['invalidtype'] = 'The provided type is invalid.';
$string['mainsettingspagebtn'] = 'Proctoring settings';
$string['modal:facevalidation'] = 'Face validated:';
$string['modal:faceregistration'] = 'Face registration:';
$string['modal:pending'] = 'Pending';
$string['modal:registerface'] = 'Register face';
$string['modal:screenshare'] = 'Screen sharing:';
$string['modal:shareentirescreen'] = 'Share entire screen';
$string['modal:validateface'] = 'Validate face recognition';
$string['name'] = 'Student name';
$string['no_permission'] = 'You do not have proper permission to view this page';
$string['nodata'] = 'No data found for the given criteria.';
$string['none'] = 'None';
$string['nopermission'] = 'You do not have permission to perform this action.';
$string['notenrolled'] = 'You are not enrolled in this course or do not have the required permissions.';
$string['notfoundtext'] = 'Not Found';
$string['notpermissionreport'] = 'Proctoring reports are disabled for you.';
$string['notrequired'] = 'Not required';
$string['nousersfound'] = 'No users found';
$string['numberofimages'] = 'Number of images';
$string['openwebcam'] = 'Allow your webcam to continue';
$string['photoalttext'] = 'The screen capture will appear in this box.';
$string['photonotuploaded'] = 'Photo not uploaded. Please contact to the admin.';
$string['picturesreport'] = 'View proctoring report';
$string['picturesusedreport'] = 'These are the pictures captured during the quiz.';
$string['plugin_description'] = 'Saylor Proctored Quiz enhances the security of online quizzes by capturing and verifying user identities through webcam images.';
$string['pluginname'] = 'Saylor Proctored Quiz';
$string['privacy:core_files'] = 'QuizAccess Proctoring webcam pictures';
$string['privacy:metadata'] = 'We do not share any personal data with third parties.';
$string['privacy:metadata:core_files'] = 'The Quiz Access stores users picture which has been shot by the webcam during quiz attempt.';
$string['privacy:metadata:courseid'] = 'The ID of the course that uses proctoring.';
$string['privacy:metadata:currenturl'] = 'The quiz page URL when a proctoring browser event was logged.';
$string['privacy:metadata:eventdetail'] = 'Details about a proctoring browser event, such as shortcut name or clipboard text length.';
$string['privacy:metadata:eventtype'] = 'The type of proctoring browser event that was logged.';
$string['privacy:metadata:pagevisibility'] = 'The browser document visibility state when a proctoring event was logged.';
$string['privacy:metadata:quizaccess_proctoring_logs'] = 'Moodle Quiz access Proctoring logs table that stores user\'s picture.';
$string['privacy:metadata:quizaccess_proctoring_events'] = 'Moodle Quiz access Proctoring events table that stores tab, focus, clipboard, and shortcut activity during quiz attempts.';
$string['privacy:metadata:quizid'] = 'The ID of the quiz that uses proctoring.';
$string['privacy:metadata:reportid'] = 'The related proctoring report ID.';
$string['privacy:metadata:screenshoturl'] = 'The desktop screenshot captured for a suspicious activity event.';
$string['privacy:metadata:status'] = 'The status of the proctoring.';
$string['privacy:metadata:userid'] = 'The ID of the user who took the quiz.';
$string['privacy:metadata:webcampicture'] = 'The name of the picture that has been taken by the proctoring.';
$string['proctoring:analyzeimages'] = 'Proctoring analyze images';
$string['proctoring:deletecamshots'] = 'Delete images from proctoring logs.';
$string['proctoring:getcamshots'] = 'Proctoring get webcam images';
$string['proctoring:sendcamshot'] = 'Proctoring send webcam photo';
$string['proctoring:viewreport'] = 'Proctoring view report';
$string['proctoring_report'] = 'Proctoring report';
$string['proctoringheader'] = '<strong>To continue with this quiz attempt you must open your webcam, and it will take some of your pictures randomly during the quiz.</strong>';
$string['proctoringlabel'] = 'I agree with the validation process.';
$string['proctoringrequired'] = 'Webcam identity validation';
$string['proctoringrequired_help'] = 'Enabling proctoring requires students to be monitored using webcam and screen recording during the quiz attempt.';
$string['proctoringrequiredoption'] = 'Enable webcam capture by Proctoring';
$string['proctoringstatement'] = 'This exam requires webcam access.<br />(Please allow webcam access).';
$string['provide_image'] = 'Please provide an image to upload.';
$string['quizaccess_proctoring'] = 'Saylor Proctored Quiz';
$string['quiztitle'] = 'Quiz Title';
$string['requireentirescreen'] = 'Entire screen share requirement';
$string['requireentirescreen_help'] = 'Controls whether students must share their entire screen before the quiz start controls are enabled. Inherit uses the site-wide Saylor Proctored Quiz setting.';
$string['requireentirescreen_disabled'] = 'Do not require entire screen share';
$string['requireentirescreen_enabled'] = 'Require entire screen share';
$string['requireentirescreen_inherit'] = 'Use site default';
$string['report_search_clear'] = 'Clear';
$string['report_search_placeholder'] = 'Search by email or name';
$string['report_search_submit'] = 'Search';
$string['reportpage'] = 'Course Proctoring Summary';
$string['reportoverview'] = 'Overview';
$string['reportoverview:clipboardactivity'] = 'Did copy, cut, paste, or right-click occur?';
$string['reportoverview:f12pressed'] = 'Was the F12 button pressed?';
$string['reportoverview:logfound'] = '{$a} Log Found';
$string['reportoverview:nologfound'] = 'No Log Found';
$string['reportoverview:overview'] = 'Overview';
$string['reportoverview:possibleaitool'] = 'Was a possible AI tool clicked?';
$string['reportoverview:screenfocuslost'] = 'Was the screen focus lost?';
$string['reportoverview:screenshareissue'] = 'Was the screen share interrupted or wrong monitor shared?';
$string['reportoverview:status'] = 'Status';
$string['reportoverview:webcamenabled'] = 'Was the webcam enabled?';
$string['setting:adminimagedescription'] = 'These images will be used as base images for face verification. Please ensure each image contains a clearly visible face.';
$string['setting:adminimagepage'] = 'Proctoring User List';

$string['setting:camshotdelay'] = 'The delay between webcam images (seconds)';
$string['setting:camshotdelay_desc'] = 'The given value will be the delay in seconds between each webcam image.';
$string['setting:camshotwidth'] = 'The width of the webcam image (pixels)';
$string['setting:camshotwidth_desc'] = 'The given value will be the width of the webcam image. The image height will be scaled to match this.';
$string['setting:custom_ai_endpoint'] = 'Saylor AI endpoint URL';
$string['setting:custom_ai_endpoint_desc'] = 'The URL of the Saylor face match API endpoint, for example http://your-server-ip:8000/verify.';
$string['setting:custom_api_key'] = 'Custom API key';
$string['setting:custom_api_key_desc'] = 'API key sent to the custom face match API in the X-API-Key header.';
$string['setting:blockclipboard'] = 'Block copy, cut, and paste during quiz';
$string['setting:blockclipboard_desc'] = 'If enabled, normal browser copy, cut, paste, right-click menu, and keyboard clipboard shortcuts are blocked during proctored quiz attempts and logged as clipboard events.';
$string['setting:continuousfacecheck'] = 'Continuous face checks during quiz';
$string['setting:continuousfacecheck_desc'] = 'If enabled, each configured webcam capture can be sent to the selected face match service during the quiz attempt.';
$string['setting:continuousfacecheckevery'] = 'Run continuous check every N captures';
$string['setting:continuousfacecheckevery_desc'] = 'Use 1 to check every webcam capture. Use a higher number to reduce cost, for example 4 checks every fourth capture.';
$string['setting:captureviolationdesktop'] = 'Capture desktop on browser violations';
$string['setting:captureviolationdesktop_desc'] = 'If enabled, students must share their entire screen during the attempt and suspicious browser activity events include a desktop screenshot when available.';
$string['setting:monitorbrowseractivity'] = 'Monitor browser activity during quiz';
$string['setting:monitorbrowseractivity_desc'] = 'Logs tab visibility changes, focus loss, clipboard actions, monitored shortcuts, and possible in-page AI tool clicks during proctored quiz attempts.';
$string['setting:requireentirescreen'] = 'Require entire screen share before quiz start';
$string['setting:requireentirescreen_desc'] = 'If enabled, students must share their entire screen in the browser screen-share prompt before the quiz start controls are enabled.';
$string['setting:facematch'] = 'Number of face matches per quiz';
$string['setting:facematchdesc'] = 'Number of face match checks. Use 0 or less to check all snapshots.';
$string['setting:fc_method'] = 'Face match method';
$string['setting:fc_method_customapi'] = 'Custom AI API';
$string['setting:fc_methoddesc'] = 'Service used to match faces. Options: Custom AI API, None.';
$string['setting:rekognitioncostpercheck'] = 'Estimated cost per face-match check';
$string['setting:rekognitioncostpercheck_desc'] = 'Used only for the admin cost estimate page. Default is 0.001 USD per AWS Rekognition CompareFaces check.';
$string['setting:fcthreshold'] = 'Face match threshold percentage';
$string['setting:fcthresholddesc'] = 'Face match threshold percentage';
$string['setting:uploaduserimages'] = 'Upload base image for users';
$string['setting:userslist'] = 'Upload user images';
$string['settings:deleteallsuccess'] = 'Successfully deleted all records.';
$string['settings:deleteuserimagesuccess'] = 'Successfully deleted user image.';
$string['settings:fcheckquizstart'] = 'Face validation on quiz start';
$string['settings:fcheckquizstart_desc'] = 'If enabled, users must validate their face before they can start the quiz.';
$string['screenshareaccepted'] = 'Entire screen shared. You can continue.';
$string['screensharedenied'] = 'Screen sharing was cancelled or blocked. Share your entire screen to continue.';
$string['screensharenotsupported'] = 'This browser does not support screen sharing. Use a supported browser such as Chrome or Edge.';
$string['screensharestopped'] = 'Screen sharing stopped. Share your entire screen again to continue.';
$string['screenmarkerlabel'] = 'Screen check';
$string['screenmarkerwrongmonitor'] = 'The shared screen does not show this quiz page. Move the quiz window to the shared monitor or share the monitor containing the quiz.';
$string['screenmonitor:instructions'] = 'Keep this window open while you take the quiz. It holds the screen share so Moodle does not ask again on every quiz page.';
$string['screenmonitor:popupblocked'] = 'The screen monitor window was blocked. Allow pop-ups for this site and try again.';
$string['screenmonitor:ready'] = 'Screen share is active. Return to the quiz and keep this window open.';
$string['screenmonitor:share'] = 'Share entire screen';
$string['screenmonitor:stopped'] = 'Screen sharing stopped. Share your entire screen again.';
$string['screenmonitor:title'] = 'Saylor proctoring screen monitor';
$string['screenmonitor:unsupported'] = 'This browser does not support screen sharing. Use a supported browser such as Chrome or Edge.';
$string['screenmonitor:windowopened'] = 'A screen monitor window opened. Share the entire screen there, then return to the quiz.';
$string['screenmonitor:wrongmonitor'] = 'The shared screen does not show the quiz screen marker. Share the monitor containing the quiz.';

$string['settingscontroll:deleteall'] = 'Delete all record that captured during the exams';
$string['settingscontroll:deleteallcourseimage'] = 'Delete all images and records of students that were captured during the exams for <b>this course</b>.';
$string['settingscontroll:deletealldescription'] = 'This will permanently delete all captured images and proctoring related data. This action cannot be undone.';

$string['settingscontroll:deletealllinktext'] = 'Delete all records';
$string['status'] = 'Validation status';
$string['studentreport'] = 'Student report';
$string['submit'] = 'Submit';
$string['suspiciousactivity'] = 'Suspicious activity';
$string['summarypagedesc'] = 'In this report you will find the summary of proctoring report for this course and its quizzes. You can delete all the data related to quiz and course. It will delete image file as well as logs.';
$string['task:delete_images'] = 'Delete images task';
$string['timemodified'] = 'Last modified';
$string['upload_first_image'] = 'Please upload user image.';
$string['upload_image'] = 'Upload image';
$string['upload_image_heading'] = 'Upload user image';
$string['upload_image_info'] = 'Upload images to the system for user verification. This helps ensure the integrity of your online quizzes.';
$string['upload_image_link_text'] = 'Click here to Upload user images.';
$string['upload_image_message'] = 'Proctoring needs user images to authenticate their identity.';
$string['upload_image_title'] = 'Upload image for face detection';
$string['uploadimagehere'] = 'Click here to upload the image.';
$string['user'] = 'Users';
$string['user_image_not_uploaded'] = 'User image is not uploaded. Please upload the image.';
$string['user_image_not_uploaded_teacher'] = 'User image is not uploaded. Please contact with administrator to upload the image.';
$string['userimagenotuploaded'] = 'User image is not uploaded.';
$string['userlist'] = 'User list';
$string['username'] = 'User Name';
$string['users_list'] = 'Saylor Proctored Quiz Users list';
$string['users_list_info_description'] = 'This page lists all users who require a base image for proctoring.
                                        These images will be used for face-matching during quizzes to ensure authentication and prevent impersonation.
                                        If an image is not uploaded, the user may not be properly verified during proctored exams.';
$string['videonotavailable'] = 'Video stream not available.';
$string['viewimages'] = 'View images';
$string['warning:cameraallowwarning'] = 'Please allow camera access.';
$string['warninglabel'] = 'Warnings';
$string['pagevisibility'] = 'Page state';
$string['webcam'] = 'Webcam';
$string['webcampicture'] = 'Captured pictures';
$string['wrong_during_taking_image'] = 'Something went wrong during taking the image.';
$string['wrong_during_taking_screenshot'] = 'Something went wrong during taking screenshot.';
$string['youmustagree'] = 'You must agree to validate your identity before continuing.';
