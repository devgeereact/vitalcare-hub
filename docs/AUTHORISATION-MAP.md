# Authorisation map: policy, PHP check, test

Status: PLANNED. Generated from a static replay of the 95 source migrations (`scripts` not committed; see `docs/inventory/`). The source policies were **not** compared with the live `pg_policies` (no live Supabase access, by instruction), so this is an ASSUMPTION about the source's final state. No PHP check or test below exists yet. Each row becomes DONE only when its test has been seen to pass.

## How to read this

- Every request is authenticated by the Clerk token check (`Jwt.php`), then resolved to a `users` row by `clerk_user_id`. Role, organisation and id come from that row, never from the request.
- One class, `Authz`, holds every rule. Handlers call `$z->require(...)`; no handler contains its own role test. Fail closed: an unknown role, a missing row or an inactive user is denied.
- `ME` below means the authenticated user's id (`$z->userId`). `$z->staffFor($u)` is the check whose tenant scope needs your decision **D6** (see `docs/DECISIONS.md`).
- Roles in the new system: super_admin, admin, manager, trainer, learner. `content_editor` is merged into `trainer`; `guest` is dropped. Source `is_staff()` = trainer, admin, super_admin (managers were NOT staff). `isInternal()` = trainer, manager, admin, super_admin.
- **Test id** is the name of the PHP test that must exist and pass: `Authz\<Table>Test::<policy>`. Each test asserts at least one allowed principal and one denied principal, plus the unauthenticated case.
- **Phase** is the roadmap slice that ports the row. `R5` rows are deferred: their check is specified now so nothing is lost, but no endpoint exists until the feature returns. `CUT` rows are not ported (the table is dropped from V1).

## Verified so far

The predicates every row is built from exist in `backend/src/Authz.php` and are tested in `backend/tests/suites/020-authz.php` (82 assertions: the role matrix, fail-closed for nobody, inactive users and retired or unknown roles, `canReadPerson`, `sameOrg`, `staffFor` under D-010). Identity resolution is tested in `010-session-resolver.php`. **No row of the table below is DONE yet**: each becomes DONE when its own test passes in its slice.

## Counts

- Public-schema policies replayed: **162**; storage policies: **9**.
- By phase: CUT: 8, R1: 8, R2: 46, R3: 34, R4: 19, R5: 47.
- Tables with row security on and no policy (deny-all, service role only in the source): app_config, google_oauth_tokens, integration_settings, user_mail_accounts. All four are CUT.

## Policies (public schema)

| # | Source table | Policy | Op | Phase | Predicate as PHP (USING / WITH CHECK) | PHP check | Test id |
|---|---|---|---|---|---|---|---|
| 1 | mail_messages | mail_delete | delete | CUT | USING (owner_id = $z->userId) or ((owner_id is null) and $z->isStaff()) | not ported (table cut) | none |
| 2 | mail_messages | mail_insert | insert | CUT | CHECK (owner_id = $z->userId) or ((owner_id is null) and $z->isStaff()) | not ported (table cut) | none |
| 3 | mail_messages | mail_select | select | CUT | USING owner_id = $z->userId or (owner_id is null and $z->isStaff()) | not ported (table cut) | none |
| 4 | mail_messages | mail_update | update | CUT | USING owner_id = $z->userId or (owner_id is null and $z->isStaff()) ; CHECK owner_id = $z->userId or (owner_id is null and $z->isStaff()) | not ported (table cut) | none |
| 5 | push_subscriptions | push_subs_all | all | CUT | USING user_id = $z->userId ; CHECK user_id = $z->userId | not ported (table cut) | none |
| 6 | recurring_templates | templates_all | all | CUT | USING $z->isStaff() ; CHECK $z->isStaff() | not ported (table cut) | none |
| 7 | trainer_availability | trainer_avail_read | select | CUT | USING $z->authenticated() | not ported (table cut) | none |
| 8 | trainer_availability | trainer_avail_write | all | CUT | USING trainer_id = $z->userId or $z->isAdmin() ; CHECK trainer_id = $z->userId or $z->isAdmin() | not ported (table cut) | none |
| 9 | course_categories | categories_read | select | R1 | USING true | `Authz::categoriesRead` | `Authz\CourseCategoriesTest::categoriesRead` |
| 10 | course_categories | categories_write | all | R1 | USING $z->isAdmin() ; CHECK $z->isAdmin() | `Authz::categoriesWrite` | `Authz\CourseCategoriesTest::categoriesWrite` |
| 11 | course_faqs | course_faqs_select | select | R1 | USING $z->authenticated() | `Authz::courseFaqsSelect` | `Authz\CourseFaqsTest::courseFaqsSelect` |
| 12 | course_faqs | course_faqs_write | all | R1 | USING $z->isStaff() ; CHECK $z->isStaff() | `Authz::courseFaqsWrite` | `Authz\CourseFaqsTest::courseFaqsWrite` |
| 13 | course_prerequisites | course_prereqs_select | select | R1 | USING $z->authenticated() | `Authz::coursePrereqsSelect` | `Authz\CoursePrerequisitesTest::coursePrereqsSelect` |
| 14 | course_prerequisites | course_prereqs_write | all | R1 | USING $z->isStaff() ; CHECK $z->isStaff() | `Authz::coursePrereqsWrite` | `Authz\CoursePrerequisitesTest::coursePrereqsWrite` |
| 15 | courses | courses_read | select | R1 | USING is_published or $z->isStaff() or exists ( select 1 from public.enrollments e where e.course_id = courses.id and e.learner_id = $z->userId and e.deleted_at is null ) | `Authz::coursesRead` | `Authz\CoursesTest::coursesRead` |
| 16 | courses | courses_write | all | R1 | USING $z->isStaff() ; CHECK $z->isStaff() | `Authz::coursesWrite` | `Authz\CoursesTest::coursesWrite` |
| 17 | assessment_attempts | attempts_insert_own | insert | R2 | CHECK learner_id = $z->userId | `Authz::attemptsInsertOwn` | `Authz\AssessmentAttemptsTest::attemptsInsertOwn` |
| 18 | assessment_attempts | attempts_select | select | R2 | USING learner_id = $z->userId or $z->staffFor($learner_id) | `Authz::attemptsSelect` | `Authz\AssessmentAttemptsTest::attemptsSelect` |
| 19 | assessment_attempts | attempts_update | update | R2 | USING $z->staffFor($learner_id) ; CHECK $z->staffFor($learner_id) | `Authz::attemptsUpdate` | `Authz\AssessmentAttemptsTest::attemptsUpdate` |
| 20 | assessments | assessments_read | select | R2 | USING $z->authenticated() | `Authz::assessmentsRead` | `Authz\AssessmentsTest::assessmentsRead` |
| 21 | assessments | assessments_write | all | R2 | USING $z->isStaff() ; CHECK $z->isStaff() | `Authz::assessmentsWrite` | `Authz\AssessmentsTest::assessmentsWrite` |
| 22 | attempt_answers | answers_delete_staff | delete | R2 | USING exists ( select 1 from public.assessment_attempts a where a.id = attempt_answers.attempt_id and $z->staffFor($a.learner_id) ) | `Authz::answersDeleteStaff` | `Authz\AttemptAnswersTest::answersDeleteStaff` |
| 23 | attempt_answers | answers_insert_own | insert | R2 | CHECK exists ( select 1 from public.assessment_attempts a where a.id = attempt_answers.attempt_id and (a.learner_id = $z->userId or $z->staffFor($a.learner_id)) ) | `Authz::answersInsertOwn` | `Authz\AttemptAnswersTest::answersInsertOwn` |
| 24 | attempt_answers | answers_select | select | R2 | USING exists ( select 1 from public.assessment_attempts a where a.id = attempt_answers.attempt_id and (a.learner_id = $z->userId or $z->staffFor($a.learner_id)) ) | `Authz::answersSelect` | `Authz\AttemptAnswersTest::answersSelect` |
| 25 | attempt_answers | answers_update_staff | update | R2 | USING exists ( select 1 from public.assessment_attempts a where a.id = attempt_answers.attempt_id and $z->staffFor($a.learner_id) ) ; CHECK exists ( select 1 from public.assessment_attempts a where a.id = attempt_answers.attempt_id and $z->staffFor($a.learner_id) ) | `Authz::answersUpdateStaff` | `Authz\AttemptAnswersTest::answersUpdateStaff` |
| 26 | audit_logs | audit_insert | insert | R2 | CHECK $z->authenticated() | `Authz::auditInsert` | `Authz\AuditLogTest::auditInsert` |
| 27 | audit_logs | audit_select | select | R2 | USING $z->isSuperAdmin() | `Authz::auditSelect` | `Authz\AuditLogTest::auditSelect` |
| 28 | certificate_expiry_alerts | cert_expiry_alerts_select | select | R2 | USING $z->isStaff() | `Authz::certExpiryAlertsSelect` | `Authz\CertificateExpiryAlertsTest::certExpiryAlertsSelect` |
| 29 | certificate_templates | cert_templates_all | all | R2 | USING $z->isStaff() ; CHECK $z->isStaff() | `Authz::certTemplatesAll` | `Authz\CertificateTemplatesTest::certTemplatesAll` |
| 30 | course_resources | course_resources_read | select | R2 | USING deleted_at is null and is_published = true and audience in ('learner', 'both') and ( course_id is null or exists ( select 1 from public.enrollments e where e.course_id = course_resources.course_id and e.learner_id = $z->userId ) ) and ( not exists ( select 1 from public.resource_allocations ra | `Authz::courseResourcesRead` | `Authz\CourseResourcesTest::courseResourcesRead` |
| 31 | course_resources | course_resources_staff_all | all | R2 | USING $z->isStaff() or $z->isAdmin() ; CHECK $z->isStaff() or $z->isAdmin() | `Authz::courseResourcesStaffAll` | `Authz\CourseResourcesTest::courseResourcesStaffAll` |
| 32 | enrollments | enrollments_delete_staff | delete | R2 | USING $z->staffFor($learner_id) | `Authz::enrollmentsDeleteStaff` | `Authz\EnrollmentsTest::enrollmentsDeleteStaff` |
| 33 | enrollments | enrollments_insert_own | insert | R2 | CHECK learner_id = $z->userId or $z->isStaff() | `Authz::enrollmentsInsertOwn` | `Authz\EnrollmentsTest::enrollmentsInsertOwn` |
| 34 | enrollments | enrollments_insert_staff | insert | R2 | CHECK $z->staffFor($learner_id) | `Authz::enrollmentsInsertStaff` | `Authz\EnrollmentsTest::enrollmentsInsertStaff` |
| 35 | enrollments | enrollments_select | select | R2 | USING learner_id = $z->userId or $z->staffFor($learner_id) | `Authz::enrollmentsSelect` | `Authz\EnrollmentsTest::enrollmentsSelect` |
| 36 | enrollments | enrollments_update_own | update | R2 | USING learner_id = $z->userId ; CHECK learner_id = $z->userId | `Authz::enrollmentsUpdateOwn` | `Authz\EnrollmentsTest::enrollmentsUpdateOwn` |
| 37 | enrollments | enrollments_update_staff | update | R2 | USING $z->staffFor($learner_id) ; CHECK $z->staffFor($learner_id) | `Authz::enrollmentsUpdateStaff` | `Authz\EnrollmentsTest::enrollmentsUpdateStaff` |
| 38 | learner_certificates | certs_delete | delete | R2 | USING $z->isAdmin() and $z->sameOrg($learner_id) | `Authz::certsDelete` | `Authz\CertificatesTest::certsDelete` |
| 39 | learner_certificates | certs_insert | insert | R2 | CHECK $z->staffFor($learner_id) | `Authz::certsInsert` | `Authz\CertificatesTest::certsInsert` |
| 40 | learner_certificates | certs_select | select | R2 | USING learner_id = $z->userId or $z->staffFor($learner_id) | `Authz::certsSelect` | `Authz\CertificatesTest::certsSelect` |
| 41 | learner_certificates | certs_update | update | R2 | USING $z->isAdmin() and $z->sameOrg($learner_id) ; CHECK $z->isAdmin() and $z->sameOrg($learner_id) | `Authz::certsUpdate` | `Authz\CertificatesTest::certsUpdate` |
| 42 | lesson_progress | lesson_progress_delete_own | delete | R2 | USING learner_id = $z->userId | `Authz::lessonProgressDeleteOwn` | `Authz\LessonProgressTest::lessonProgressDeleteOwn` |
| 43 | lesson_progress | lesson_progress_insert_own | insert | R2 | CHECK learner_id = $z->userId | `Authz::lessonProgressInsertOwn` | `Authz\LessonProgressTest::lessonProgressInsertOwn` |
| 44 | lesson_progress | lesson_progress_select | select | R2 | USING learner_id = $z->userId or $z->staffFor($learner_id) | `Authz::lessonProgressSelect` | `Authz\LessonProgressTest::lessonProgressSelect` |
| 45 | lesson_progress | lesson_progress_update_own | update | R2 | USING learner_id = $z->userId ; CHECK learner_id = $z->userId | `Authz::lessonProgressUpdateOwn` | `Authz\LessonProgressTest::lessonProgressUpdateOwn` |
| 46 | lessons | lessons_read | select | R2 | USING $z->authenticated() | `Authz::lessonsRead` | `Authz\LessonsTest::lessonsRead` |
| 47 | lessons | lessons_write | all | R2 | USING $z->isStaff() ; CHECK $z->isStaff() | `Authz::lessonsWrite` | `Authz\LessonsTest::lessonsWrite` |
| 48 | modules | modules_read | select | R2 | USING $z->authenticated() | `Authz::modulesRead` | `Authz\ModulesTest::modulesRead` |
| 49 | modules | modules_write | all | R2 | USING $z->isStaff() ; CHECK $z->isStaff() | `Authz::modulesWrite` | `Authz\ModulesTest::modulesWrite` |
| 50 | notifications | notifications_insert | insert | R2 | CHECK $z->isStaff() or user_id = $z->userId | `Authz::notificationsInsert` | `Authz\NotificationsTest::notificationsInsert` |
| 51 | notifications | notifications_own | select | R2 | USING user_id = $z->userId | `Authz::notificationsOwn` | `Authz\NotificationsTest::notificationsOwn` |
| 52 | notifications | notifications_update_own | update | R2 | USING user_id = $z->userId ; CHECK user_id = $z->userId | `Authz::notificationsUpdateOwn` | `Authz\NotificationsTest::notificationsUpdateOwn` |
| 53 | organisations | orgs_select | select | R2 | USING $z->isStaff() or id = (select organisation_id from public.profiles where id = $z->userId) | `Authz::orgsSelect` | `Authz\OrganisationsTest::orgsSelect` |
| 54 | organisations | orgs_write | all | R2 | USING $z->isAdmin() ; CHECK $z->isAdmin() | `Authz::orgsWrite` | `Authz\OrganisationsTest::orgsWrite` |
| 55 | profiles | profiles_delete_admin | delete | R2 | USING $z->isAdmin() | `Authz::profilesDeleteAdmin` | `Authz\UsersTest::profilesDeleteAdmin` |
| 56 | profiles | profiles_insert_admin | insert | R2 | CHECK $z->isAdmin() | `Authz::profilesInsertAdmin` | `Authz\UsersTest::profilesInsertAdmin` |
| 57 | profiles | profiles_select | select | R2 | USING id = $z->userId or $z->staffFor($id) | `Authz::profilesSelect` | `Authz\UsersTest::profilesSelect` |
| 58 | profiles | profiles_update_own | update | R2 | USING id = $z->userId or $z->isAdmin() ; CHECK id = $z->userId or $z->isAdmin() | `Authz::profilesUpdateOwn` | `Authz\UsersTest::profilesUpdateOwn` |
| 59 | question_options | options_read | select | R2 | USING $z->isStaff() | `Authz::optionsRead` | `Authz\QuestionOptionsTest::optionsRead` |
| 60 | question_options | options_write | all | R2 | USING $z->isStaff() ; CHECK $z->isStaff() | `Authz::optionsWrite` | `Authz\QuestionOptionsTest::optionsWrite` |
| 61 | questions | questions_read | select | R2 | USING $z->authenticated() | `Authz::questionsRead` | `Authz\QuestionsTest::questionsRead` |
| 62 | questions | questions_write | all | R2 | USING $z->isStaff() ; CHECK $z->isStaff() | `Authz::questionsWrite` | `Authz\QuestionsTest::questionsWrite` |
| 63 | announcement_acks | announcement_acks_insert | insert | R3 | CHECK user_id = $z->userId | `Authz::announcementAcksInsert` | `Authz\AnnouncementAcksTest::announcementAcksInsert` |
| 64 | announcement_acks | announcement_acks_select | select | R3 | USING user_id = $z->userId or $z->isStaff() | `Authz::announcementAcksSelect` | `Authz\AnnouncementAcksTest::announcementAcksSelect` |
| 65 | announcements | announcements_read | select | R3 | USING published_at is not null or $z->isStaff() | `Authz::announcementsRead` | `Authz\AnnouncementsTest::announcementsRead` |
| 66 | announcements | announcements_write | all | R3 | USING $z->isStaff() ; CHECK $z->isStaff() | `Authz::announcementsWrite` | `Authz\AnnouncementsTest::announcementsWrite` |
| 67 | attendance_records | attendance_delete_staff | delete | R3 | USING $z->staffFor($learner_id) | `Authz::attendanceDeleteStaff` | `Authz\AttendanceRecordsTest::attendanceDeleteStaff` |
| 68 | attendance_records | attendance_insert_own | insert | R3 | CHECK $z->isStaff() or ( learner_id = $z->userId and exists ( select 1 from public.session_bookings b where b.session_id = attendance_records.session_id and b.learner_id = $z->userId ) ) | `Authz::attendanceInsertOwn` | `Authz\AttendanceRecordsTest::attendanceInsertOwn` |
| 69 | attendance_records | attendance_select | select | R3 | USING learner_id = $z->userId or $z->staffFor($learner_id) | `Authz::attendanceSelect` | `Authz\AttendanceRecordsTest::attendanceSelect` |
| 70 | attendance_records | attendance_update_own | update | R3 | USING $z->isStaff() or learner_id = $z->userId ; CHECK $z->isStaff() or learner_id = $z->userId | `Authz::attendanceUpdateOwn` | `Authz\AttendanceRecordsTest::attendanceUpdateOwn` |
| 71 | blog_posts | blog_read | select | R3 | USING status = 'published' or $z->isAdmin() | `Authz::blogRead` | `Authz\BlogPostsTest::blogRead` |
| 72 | blog_posts | blog_write | all | R3 | USING $z->isAdmin() ; CHECK $z->isAdmin() | `Authz::blogWrite` | `Authz\BlogPostsTest::blogWrite` |
| 73 | calendar_events | calendar_events_select | select | R3 | USING created_by = $z->userId or $z->isStaff() | `Authz::calendarEventsSelect` | `Authz\CalendarEventsTest::calendarEventsSelect` |
| 74 | calendar_events | calendar_events_write | all | R3 | USING created_by = $z->userId ; CHECK created_by = $z->userId | `Authz::calendarEventsWrite` | `Authz\CalendarEventsTest::calendarEventsWrite` |
| 75 | cohort_members | cohort_members_select | select | R3 | USING $z->isStaff() | `Authz::cohortMembersSelect` | `Authz\CohortMembersTest::cohortMembersSelect` |
| 76 | cohort_members | cohort_members_write | all | R3 | USING $z->isStaff() ; CHECK $z->isStaff() | `Authz::cohortMembersWrite` | `Authz\CohortMembersTest::cohortMembersWrite` |
| 77 | cohorts | cohorts_select | select | R3 | USING $z->isStaff() | `Authz::cohortsSelect` | `Authz\CohortsTest::cohortsSelect` |
| 78 | cohorts | cohorts_write | all | R3 | USING $z->isStaff() ; CHECK $z->isStaff() | `Authz::cohortsWrite` | `Authz\CohortsTest::cohortsWrite` |
| 79 | one_to_one_requests | o2o_insert | insert | R3 | CHECK learner_id = $z->userId | `Authz::o2oInsert` | `Authz\OneToOneRequestsTest::o2oInsert` |
| 80 | one_to_one_requests | o2o_select | select | R3 | USING learner_id = $z->userId or trainer_id = $z->userId or $z->isStaff() | `Authz::o2oSelect` | `Authz\OneToOneRequestsTest::o2oSelect` |
| 81 | one_to_one_requests | o2o_update | update | R3 | USING $z->isStaff() ; CHECK $z->isStaff() | `Authz::o2oUpdate` | `Authz\OneToOneRequestsTest::o2oUpdate` |
| 82 | org_holidays | org_holidays_read | select | R3 | USING true | `Authz::orgHolidaysRead` | `Authz\OrgHolidaysTest::orgHolidaysRead` |
| 83 | org_holidays | org_holidays_write | all | R3 | USING exists ( select 1 from public.profiles p where p.id = $z->userId and $z->isInternal() ) ; CHECK exists ( select 1 from public.profiles p where p.id = $z->userId and $z->isInternal() ) | `Authz::orgHolidaysWrite` | `Authz\OrgHolidaysTest::orgHolidaysWrite` |
| 84 | session_bookings | bookings_delete | delete | R3 | USING learner_id = $z->userId or $z->isStaff() | `Authz::bookingsDelete` | `Authz\SessionBookingsTest::bookingsDelete` |
| 85 | session_bookings | bookings_insert_own | insert | R3 | CHECK learner_id = $z->userId or $z->isStaff() | `Authz::bookingsInsertOwn` | `Authz\SessionBookingsTest::bookingsInsertOwn` |
| 86 | session_bookings | bookings_modify | update | R3 | USING learner_id = $z->userId or $z->isStaff() ; CHECK learner_id = $z->userId or $z->isStaff() | `Authz::bookingsModify` | `Authz\SessionBookingsTest::bookingsModify` |
| 87 | session_bookings | bookings_select | select | R3 | USING learner_id = $z->userId or $z->staffFor($learner_id) | `Authz::bookingsSelect` | `Authz\SessionBookingsTest::bookingsSelect` |
| 88 | session_join_requests | sjr_insert | insert | R3 | CHECK learner_id = $z->userId | `Authz::sjrInsert` | `Authz\SessionJoinRequestsTest::sjrInsert` |
| 89 | session_join_requests | sjr_learner_update | update | R3 | USING learner_id = $z->userId ; CHECK learner_id = $z->userId and status = 'pending' | `Authz::sjrLearnerUpdate` | `Authz\SessionJoinRequestsTest::sjrLearnerUpdate` |
| 90 | session_join_requests | sjr_select | select | R3 | USING learner_id = $z->userId or $z->isStaff() | `Authz::sjrSelect` | `Authz\SessionJoinRequestsTest::sjrSelect` |
| 91 | session_join_requests | sjr_update | update | R3 | USING $z->isAdmin() ; CHECK $z->isAdmin() | `Authz::sjrUpdate` | `Authz\SessionJoinRequestsTest::sjrUpdate` |
| 92 | session_reminders | session_reminders_read | select | R3 | USING $z->isStaff() | `Authz::sessionRemindersRead` | `Authz\SessionRemindersTest::sessionRemindersRead` |
| 93 | trainer_profiles | trainer_profiles_read | select | R3 | USING true | `Authz::trainerProfilesRead` | `Authz\TrainerProfilesTest::trainerProfilesRead` |
| 94 | trainer_profiles | trainer_profiles_write | all | R3 | USING profile_id = $z->userId or $z->isAdmin() ; CHECK profile_id = $z->userId or $z->isAdmin() | `Authz::trainerProfilesWrite` | `Authz\TrainerProfilesTest::trainerProfilesWrite` |
| 95 | training_sessions | sessions_read | select | R3 | USING is_public or $z->authenticated() | `Authz::sessionsRead` | `Authz\TrainingSessionsTest::sessionsRead` |
| 96 | training_sessions | sessions_write | all | R3 | USING $z->isStaff() ; CHECK $z->isStaff() | `Authz::sessionsWrite` | `Authz\TrainingSessionsTest::sessionsWrite` |
| 97 | coupon_redemptions | coupon_redemptions_select | select | R4 | USING $z->isStaff() or redeemed_by = $z->userId | `Authz::couponRedemptionsSelect` | `Authz\CouponRedemptionsTest::couponRedemptionsSelect` |
| 98 | coupons | coupons_select | select | R4 | USING $z->authenticated() | `Authz::couponsSelect` | `Authz\CouponsTest::couponsSelect` |
| 99 | coupons | coupons_write | all | R4 | USING $z->isStaff() ; CHECK $z->isStaff() | `Authz::couponsWrite` | `Authz\CouponsTest::couponsWrite` |
| 100 | feedback_responses | feedback_insert | insert | R4 | CHECK status = 'pending' ; unauthenticated allowed: public endpoint, rate-limited, status forced to pending | `Authz::feedbackInsert` | `Authz\FeedbackResponsesTest::feedbackInsert` |
| 101 | feedback_responses | feedback_select | select | R4 | USING (learner_id = $z->userId) or $z->isStaff() or (status = 'approved') | `Authz::feedbackSelect` | `Authz\FeedbackResponsesTest::feedbackSelect` |
| 102 | feedback_responses | feedback_update | update | R4 | USING $z->isStaff() ; CHECK $z->isStaff() | `Authz::feedbackUpdate` | `Authz\FeedbackResponsesTest::feedbackUpdate` |
| 103 | invoices | invoices_select | select | R4 | USING recipient_id = $z->userId or $z->isStaff() | `Authz::invoicesSelect` | `Authz\InvoicesTest::invoicesSelect` |
| 104 | invoices | invoices_write | all | R4 | USING $z->isStaff() ; CHECK $z->isStaff() | `Authz::invoicesWrite` | `Authz\InvoicesTest::invoicesWrite` |
| 105 | order_items | order_items_insert | insert | R4 | CHECK $z->isStaff() | `Authz::orderItemsInsert` | `Authz\OrderItemsTest::orderItemsInsert` |
| 106 | order_items | order_items_select | select | R4 | USING exists ( select 1 from public.orders o where o.id = order_id and (o.buyer_id = $z->userId or $z->isStaff()) ) | `Authz::orderItemsSelect` | `Authz\OrderItemsTest::orderItemsSelect` |
| 107 | orders | orders_insert | insert | R4 | CHECK $z->isStaff() | `Authz::ordersInsert` | `Authz\OrdersTest::ordersInsert` |
| 108 | orders | orders_select | select | R4 | USING buyer_id = $z->userId or $z->isStaff() | `Authz::ordersSelect` | `Authz\OrdersTest::ordersSelect` |
| 109 | orders | orders_update | update | R4 | USING $z->isStaff() ; CHECK $z->isStaff() | `Authz::ordersUpdate` | `Authz\OrdersTest::ordersUpdate` |
| 110 | products | products_select | select | R4 | USING is_published or $z->isStaff() | `Authz::productsSelect` | `Authz\ProductsTest::productsSelect` |
| 111 | products | products_write | all | R4 | USING $z->isStaff() ; CHECK $z->isStaff() | `Authz::productsWrite` | `Authz\ProductsTest::productsWrite` |
| 112 | staff_training_records | str_rec_select | select | R4 | USING $z->isInternal() | `Authz::strRecSelect` | `Authz\StaffTrainingRecordsTest::strRecSelect` |
| 113 | staff_training_records | str_rec_write | all | R4 | USING $z->isManager() ; CHECK $z->isManager() | `Authz::strRecWrite` | `Authz\StaffTrainingRecordsTest::strRecWrite` |
| 114 | staff_training_requirements | str_req_select | select | R4 | USING $z->isInternal() | `Authz::strReqSelect` | `Authz\StaffTrainingRequirementsTest::strReqSelect` |
| 115 | staff_training_requirements | str_req_write | all | R4 | USING $z->isManager() ; CHECK $z->isManager() | `Authz::strReqWrite` | `Authz\StaffTrainingRequirementsTest::strReqWrite` |
| 116 | ai_conversations | ai_own | all | R5 | USING user_id = $z->userId ; CHECK user_id = $z->userId | `Authz::aiOwn` | `Authz\AiConversationsTest::aiOwn` |
| 117 | blog_post_likes | blog_likes_read | select | R5 | USING true | `Authz::blogLikesRead` | `Authz\BlogPostLikesTest::blogLikesRead` |
| 118 | blog_post_likes | blog_likes_write | all | R5 | USING user_id = $z->userId ; CHECK user_id = $z->userId | `Authz::blogLikesWrite` | `Authz\BlogPostLikesTest::blogLikesWrite` |
| 119 | course_reviews | course_reviews_delete | delete | R5 | USING learner_id = $z->userId or $z->isStaff() | `Authz::courseReviewsDelete` | `Authz\CourseReviewsTest::courseReviewsDelete` |
| 120 | course_reviews | course_reviews_insert | insert | R5 | CHECK learner_id = $z->userId | `Authz::courseReviewsInsert` | `Authz\CourseReviewsTest::courseReviewsInsert` |
| 121 | course_reviews | course_reviews_select | select | R5 | USING $z->authenticated() | `Authz::courseReviewsSelect` | `Authz\CourseReviewsTest::courseReviewsSelect` |
| 122 | course_reviews | course_reviews_update | update | R5 | USING learner_id = $z->userId ; CHECK learner_id = $z->userId | `Authz::courseReviewsUpdate` | `Authz\CourseReviewsTest::courseReviewsUpdate` |
| 123 | department_members | department_members_select | select | R5 | USING $z->isStaff() or user_id = $z->userId | `Authz::departmentMembersSelect` | `Authz\DepartmentMembersTest::departmentMembersSelect` |
| 124 | department_members | department_members_write | all | R5 | USING $z->isAdmin() ; CHECK $z->isAdmin() | `Authz::departmentMembersWrite` | `Authz\DepartmentMembersTest::departmentMembersWrite` |
| 125 | department_tasks | department_tasks_delete | delete | R5 | USING $z->isStaff() or public.$z->inDepartment($department_id) | `Authz::departmentTasksDelete` | `Authz\DepartmentTasksTest::departmentTasksDelete` |
| 126 | department_tasks | department_tasks_insert | insert | R5 | CHECK $z->isStaff() or public.$z->inDepartment($department_id) | `Authz::departmentTasksInsert` | `Authz\DepartmentTasksTest::departmentTasksInsert` |
| 127 | department_tasks | department_tasks_select | select | R5 | USING $z->isStaff() or public.$z->inDepartment($department_id) | `Authz::departmentTasksSelect` | `Authz\DepartmentTasksTest::departmentTasksSelect` |
| 128 | department_tasks | department_tasks_update | update | R5 | USING $z->isStaff() or public.$z->inDepartment($department_id) ; CHECK $z->isStaff() or public.$z->inDepartment($department_id) | `Authz::departmentTasksUpdate` | `Authz\DepartmentTasksTest::departmentTasksUpdate` |
| 129 | departments | depts_select | select | R5 | USING $z->isStaff() or organisation_id = (select organisation_id from public.profiles where id = $z->userId) | `Authz::deptsSelect` | `Authz\DepartmentsTest::deptsSelect` |
| 130 | departments | depts_write | all | R5 | USING $z->isAdmin() ; CHECK $z->isAdmin() | `Authz::deptsWrite` | `Authz\DepartmentsTest::deptsWrite` |
| 131 | email_campaigns | email_campaigns_insert | insert | R5 | CHECK $z->isStaff() and created_by = $z->userId | `Authz::emailCampaignsInsert` | `Authz\EmailCampaignsTest::emailCampaignsInsert` |
| 132 | email_campaigns | email_campaigns_select | select | R5 | USING $z->isStaff() | `Authz::emailCampaignsSelect` | `Authz\EmailCampaignsTest::emailCampaignsSelect` |
| 133 | email_campaigns | email_campaigns_update | update | R5 | USING $z->isStaff() ; CHECK $z->isStaff() | `Authz::emailCampaignsUpdate` | `Authz\EmailCampaignsTest::emailCampaignsUpdate` |
| 134 | forum_post_likes | forum_post_likes_delete | delete | R5 | USING user_id = $z->userId | `Authz::forumPostLikesDelete` | `Authz\ForumPostLikesTest::forumPostLikesDelete` |
| 135 | forum_post_likes | forum_post_likes_insert | insert | R5 | CHECK user_id = $z->userId | `Authz::forumPostLikesInsert` | `Authz\ForumPostLikesTest::forumPostLikesInsert` |
| 136 | forum_post_likes | forum_post_likes_select | select | R5 | USING $z->authenticated() | `Authz::forumPostLikesSelect` | `Authz\ForumPostLikesTest::forumPostLikesSelect` |
| 137 | forum_posts | forum_posts_delete | delete | R5 | USING author_id = $z->userId or $z->isStaff() | `Authz::forumPostsDelete` | `Authz\ForumPostsTest::forumPostsDelete` |
| 138 | forum_posts | forum_posts_insert | insert | R5 | CHECK author_id = $z->userId | `Authz::forumPostsInsert` | `Authz\ForumPostsTest::forumPostsInsert` |
| 139 | forum_posts | forum_posts_select | select | R5 | USING $z->authenticated() | `Authz::forumPostsSelect` | `Authz\ForumPostsTest::forumPostsSelect` |
| 140 | forum_posts | forum_posts_update | update | R5 | USING author_id = $z->userId or $z->isStaff() ; CHECK author_id = $z->userId or $z->isStaff() | `Authz::forumPostsUpdate` | `Authz\ForumPostsTest::forumPostsUpdate` |
| 141 | forum_threads | forum_threads_delete | delete | R5 | USING created_by = $z->userId or $z->isStaff() | `Authz::forumThreadsDelete` | `Authz\ForumThreadsTest::forumThreadsDelete` |
| 142 | forum_threads | forum_threads_insert | insert | R5 | CHECK created_by = $z->userId | `Authz::forumThreadsInsert` | `Authz\ForumThreadsTest::forumThreadsInsert` |
| 143 | forum_threads | forum_threads_select | select | R5 | USING $z->authenticated() | `Authz::forumThreadsSelect` | `Authz\ForumThreadsTest::forumThreadsSelect` |
| 144 | forum_threads | forum_threads_update | update | R5 | USING created_by = $z->userId or $z->isStaff() ; CHECK created_by = $z->userId or $z->isStaff() | `Authz::forumThreadsUpdate` | `Authz\ForumThreadsTest::forumThreadsUpdate` |
| 145 | learning_path_courses | lpc_select | select | R5 | USING $z->authenticated() | `Authz::lpcSelect` | `Authz\LearningPathCoursesTest::lpcSelect` |
| 146 | learning_path_courses | lpc_write | all | R5 | USING $z->isStaff() ; CHECK $z->isStaff() | `Authz::lpcWrite` | `Authz\LearningPathCoursesTest::lpcWrite` |
| 147 | learning_paths | learning_paths_select | select | R5 | USING is_published or $z->isStaff() | `Authz::learningPathsSelect` | `Authz\LearningPathsTest::learningPathsSelect` |
| 148 | learning_paths | learning_paths_write | all | R5 | USING $z->isStaff() ; CHECK $z->isStaff() | `Authz::learningPathsWrite` | `Authz\LearningPathsTest::learningPathsWrite` |
| 149 | messages | messages_insert | insert | R5 | CHECK sender_id = $z->userId | `Authz::messagesInsert` | `Authz\MessagesTest::messagesInsert` |
| 150 | messages | messages_select | select | R5 | USING sender_id = $z->userId or recipient_id = $z->userId | `Authz::messagesSelect` | `Authz\MessagesTest::messagesSelect` |
| 151 | messages | messages_update | update | R5 | USING recipient_id = $z->userId ; CHECK recipient_id = $z->userId | `Authz::messagesUpdate` | `Authz\MessagesTest::messagesUpdate` |
| 152 | payroll | payroll_delete | delete | R5 | USING $z->isAdmin() | `Authz::payrollDelete` | `Authz\PayrollTest::payrollDelete` |
| 153 | payroll | payroll_insert | insert | R5 | CHECK $z->isAdmin() | `Authz::payrollInsert` | `Authz\PayrollTest::payrollInsert` |
| 154 | payroll | payroll_select | select | R5 | USING staff_id = $z->userId or $z->isAdmin() | `Authz::payrollSelect` | `Authz\PayrollTest::payrollSelect` |
| 155 | payroll | payroll_update | update | R5 | USING $z->isAdmin() ; CHECK $z->isAdmin() | `Authz::payrollUpdate` | `Authz\PayrollTest::payrollUpdate` |
| 156 | reminders | reminders_all | all | R5 | USING user_id = $z->userId ; CHECK user_id = $z->userId | `Authz::remindersAll` | `Authz\RemindersTest::remindersAll` |
| 157 | resource_allocations | resource_allocations_self_read | select | R5 | USING learner_id = $z->userId | `Authz::resourceAllocationsSelfRead` | `Authz\ResourceAllocationsTest::resourceAllocationsSelfRead` |
| 158 | resource_allocations | resource_allocations_staff_all | all | R5 | USING $z->isStaff() or $z->isAdmin() ; CHECK $z->isStaff() or $z->isAdmin() | `Authz::resourceAllocationsStaffAll` | `Authz\ResourceAllocationsTest::resourceAllocationsStaffAll` |
| 159 | subscription_plans | plans_read | select | R5 | USING true | `Authz::plansRead` | `Authz\SubscriptionPlansTest::plansRead` |
| 160 | subscription_plans | plans_write | all | R5 | USING $z->isSuperAdmin() ; CHECK $z->isSuperAdmin() | `Authz::plansWrite` | `Authz\SubscriptionPlansTest::plansWrite` |
| 161 | subscriptions | subs_select | select | R5 | USING $z->isAdmin() or organisation_id = (select organisation_id from public.profiles where id = $z->userId) | `Authz::subsSelect` | `Authz\SubscriptionsTest::subsSelect` |
| 162 | subscriptions | subs_write | all | R5 | USING $z->isAdmin() ; CHECK $z->isAdmin() | `Authz::subsWrite` | `Authz\SubscriptionsTest::subsWrite` |

## Storage policies

Source buckets: `course-media` (public), `files` (private), `certificate-signatures` (public). All three become one private folder served only by `GET /api/files/{id}`, which applies the check below. Public images that must be visible without sign-in (course banners) are published as explicit, named public assets, not by making a folder public.

| Policy | Op | Source rule | PHP check | Test id |
|---|---|---|---|---|
| course_media_write | insert | bucket_id = 'course-media' and $z->isStaff() | `Authz::filesInsert` (staff only, file row must exist, path from the database never the request) | `Authz\FilesTest::courseMediaWrite` |
| course_media_update | update | bucket_id = 'course-media' and $z->isStaff() | `Authz::filesUpdate` (staff only, file row must exist, path from the database never the request) | `Authz\FilesTest::courseMediaUpdate` |
| course_media_delete | delete | bucket_id = 'course-media' and $z->isStaff() | `Authz::filesDelete` (staff only, file row must exist, path from the database never the request) | `Authz\FilesTest::courseMediaDelete` |
| files_select | select | bucket_id = 'files' and $z->isStaff() | `Authz::filesSelect` (staff only, file row must exist, path from the database never the request) | `Authz\FilesTest::filesSelect` |
| files_insert | insert | bucket_id = 'files' and $z->isStaff() | `Authz::filesInsert` (staff only, file row must exist, path from the database never the request) | `Authz\FilesTest::filesInsert` |
| files_delete | delete | bucket_id = 'files' and $z->isStaff() | `Authz::filesDelete` (staff only, file row must exist, path from the database never the request) | `Authz\FilesTest::filesDelete` |
| cert_sig_write | insert | bucket_id = 'certificate-signatures' and $z->isStaff() | `Authz::filesInsert` (staff only, file row must exist, path from the database never the request) | `Authz\FilesTest::certSigWrite` |
| cert_sig_update | update | bucket_id = 'certificate-signatures' and $z->isStaff() | `Authz::filesUpdate` (staff only, file row must exist, path from the database never the request) | `Authz\FilesTest::certSigUpdate` |
| cert_sig_delete | delete | bucket_id = 'certificate-signatures' and $z->isStaff() | `Authz::filesDelete` (staff only, file row must exist, path from the database never the request) | `Authz\FilesTest::certSigDelete` |

## Rules the source enforced outside policies (triggers and functions) that also need a check and a test

| Source | Rule | PHP home | Test id (PLANNED) |
|---|---|---|---|
| guard_profile_privileged_cols (064, 092) | A user cannot change their own role, organisation or verification flags | `UserService::updateSelf` allows an explicit field list only | `UserSelfUpdateTest::cannotEscalate` |
| handle_new_user (092) | A new account never receives a privileged role | First sign-in links an existing `users` row by verified email; unknown email gets no access | `SessionResolverTest::unknownEmailHasNoAccess` |
| guard_order_status (062), place_order (094) | Price and status come from the server, never the browser | `OrderService::place` prices from the catalogue inside one transaction | `OrderServiceTest::ignoresClientPrice` |
| confirm_order, set_order_status (094) | Only pending can be confirmed; cancel and refund transitions are audited | `OrderService::transition` | `OrderServiceTest::transitions` |
| redeem_coupon, redeem_coupon_for_order (092) | Coupon is re-checked under a row lock and released on cancel | `CouponService` | `CouponServiceTest::lockedRedeem` |
| guard_attendance_self_insert (062) | A learner may only mark attendance for a session they booked | `AttendanceService::selfCheckIn` | `AttendanceServiceTest::mustBeBooked` |
| get_question_options, 067/068/077 | Answer keys are never sent to learners | `AssessmentService::questionsForLearner` strips `is_correct` | `AssessmentServiceTest::noAnswerKeyLeak` |
| submit_assessment_attempt (093) | Grading is server-side, attempts are capped | `AssessmentService::submit` | `AssessmentServiceTest::gradesAndCaps` |
| sync_course_completion (097), triggers 091 | Completion and certificate expiry are computed on the server from the course renewal period | `CompletionService::sync` | `CompletionServiceTest::setsExpiry` |
| approve_certificate (083) | Only an internal approver can issue; a certificate is not valid until approved | `CertificateService::approve` | `CertificateServiceTest::unapprovedIsNotIssued` |
| verify_certificate (095) | Public verification returns valid, expired or not issued; no personal data for not issued; rate-limited | `GET /api/verify/{code}` | `VerifyEndpointTest::threeStatesAndNoLeak` |
| archive_course, delete_course_permanently (092) | Deleting a course with enrolments is refused | `CourseService::archive` | `CourseServiceTest::refusesDeleteWithEnrolments` |
| get_session_join_link (076) | The meeting link is visible only to enrolled or booked users and staff | `SessionService::joinLink` | `SessionServiceTest::linkVisibility` |
