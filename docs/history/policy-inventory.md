# RLS policy inventory (final state by static replay of 95 migrations)

Status: ASSUMPTION. Derived by replaying CREATE/DROP/ALTER POLICY in filename order. Not compared with live `pg_policies` (no access to live Supabase, by instruction). Migration 055 drops and recreates every policy with `(select auth.uid())` wrappers, so text differs trivially from the originals.

Public schema: 162 policies. Storage schema: 9. Tables with RLS on and zero policies (deny-all, reached only by service role): app_config, google_oauth_tokens, integration_settings, user_mail_accounts.

Phase 3 will turn each row into: policy | PHP check | test.

| Table | Policy | Cmd | Role | USING | WITH CHECK | Migration |
|---|---|---|---|---|---|---|
| ai_conversations | ai_own | all | public | user_id = auth.uid() | user_id = auth.uid() | 002 |
| announcement_acks | announcement_acks_insert | insert | public |  | user_id = auth.uid() | 018 |
| announcement_acks | announcement_acks_select | select | public | user_id = auth.uid() or public.is_staff() |  | 018 |
| announcements | announcements_read | select | public | published_at is not null or public.is_staff() |  | 002 |
| announcements | announcements_write | all | public | public.is_staff() | public.is_staff() | 002 |
| assessment_attempts | attempts_insert_own | insert | public |  | learner_id = auth.uid() | 002 |
| assessment_attempts | attempts_select | select | public | learner_id = (select auth.uid()) or private.staff_for(learner_id) |  | 092 |
| assessment_attempts | attempts_update | update | authenticated | private.staff_for(learner_id) | private.staff_for(learner_id) | 092 |
| assessments | assessments_read | select | public | auth.uid() is not null |  | 002 |
| assessments | assessments_write | all | public | public.is_staff() | public.is_staff() | 002 |
| attempt_answers | answers_delete_staff | delete | public | exists ( select 1 from public.assessment_attempts a where a.id = attempt_answers.attempt_id and private.staff_for(a.learner_id) ) |  | 092 |
| attempt_answers | answers_insert_own | insert | public |  | exists ( select 1 from public.assessment_attempts a where a.id = attempt_answers.attempt_id and (a.learner_id = (select auth.uid()) or private.staff_for(a.learner_id)) ) | 092 |
| attempt_answers | answers_select | select | public | exists ( select 1 from public.assessment_attempts a where a.id = attempt_answers.attempt_id and (a.learner_id = (select auth.uid()) or private.staff_for(a.learner_id)) ) |  | 092 |
| attempt_answers | answers_update_staff | update | public | exists ( select 1 from public.assessment_attempts a where a.id = attempt_answers.attempt_id and private.staff_for(a.learner_id) ) | exists ( select 1 from public.assessment_attempts a where a.id = attempt_answers.attempt_id and private.staff_for(a.learner_id) ) | 092 |
| attendance_records | attendance_delete_staff | delete | public | private.staff_for(learner_id) |  | 092 |
| attendance_records | attendance_insert_own | insert | public |  | public.is_staff() or ( learner_id = auth.uid() and exists ( select 1 from public.session_bookings b where b.session_id = attendance_records.session_id and b.learner_id = auth.uid() ) ) | 026 |
| attendance_records | attendance_select | select | public | learner_id = (select auth.uid()) or private.staff_for(learner_id) |  | 092 |
| attendance_records | attendance_update_own | update | public | public.is_staff() or learner_id = auth.uid() | public.is_staff() or learner_id = auth.uid() | 026 |
| audit_logs | audit_insert | insert | public |  | auth.uid() is not null | 002 |
| audit_logs | audit_select | select | public | public.is_super_admin() |  | 002 |
| blog_post_likes | blog_likes_read | select | public | true |  | 051 |
| blog_post_likes | blog_likes_write | all | public | user_id = auth.uid() | user_id = auth.uid() | 051 |
| blog_posts | blog_read | select | public | status = 'published' or public.is_admin() |  | 051 |
| blog_posts | blog_write | all | public | public.is_admin() | public.is_admin() | 051 |
| calendar_events | calendar_events_select | select | public | created_by = auth.uid() or public.is_staff() |  | 017 |
| calendar_events | calendar_events_write | all | public | created_by = auth.uid() | created_by = auth.uid() | 017 |
| certificate_expiry_alerts | cert_expiry_alerts_select | select | public | public.is_staff() |  | 033 |
| certificate_templates | cert_templates_all | all | public | public.is_staff() | public.is_staff() | 002 |
| cohort_members | cohort_members_select | select | public | public.is_staff() |  | 011 |
| cohort_members | cohort_members_write | all | public | public.is_staff() | public.is_staff() | 011 |
| cohorts | cohorts_select | select | public | public.is_staff() |  | 011 |
| cohorts | cohorts_write | all | public | public.is_staff() | public.is_staff() | 011 |
| coupon_redemptions | coupon_redemptions_select | select | public | private.is_staff() or redeemed_by = (select auth.uid()) |  | 092 |
| coupons | coupons_select | select | public | auth.uid() is not null |  | 016 |
| coupons | coupons_write | all | public | public.is_staff() | public.is_staff() | 016 |
| course_categories | categories_read | select | public | true |  | 002 |
| course_categories | categories_write | all | public | public.is_admin() | public.is_admin() | 002 |
| course_faqs | course_faqs_select | select | public | auth.uid() is not null |  | 013 |
| course_faqs | course_faqs_write | all | public | public.is_staff() | public.is_staff() | 013 |
| course_prerequisites | course_prereqs_select | select | public | auth.uid() is not null |  | 013 |
| course_prerequisites | course_prereqs_write | all | public | public.is_staff() | public.is_staff() | 013 |
| course_resources | course_resources_read | select | authenticated | deleted_at is null and is_published = true and audience in ('learner', 'both') and ( course_id is null or exists ( select 1 from public.enrollments e where e.course_id = course_resources.course_id and e.learner_id = (select auth.uid()) ) ) and ( not exists ( s |  | 072 |
| course_resources | course_resources_staff_all | all | authenticated | public.is_staff() or public.is_admin() | public.is_staff() or public.is_admin() | 034 |
| course_reviews | course_reviews_delete | delete | public | learner_id = auth.uid() or public.is_staff() |  | 013 |
| course_reviews | course_reviews_insert | insert | public |  | learner_id = auth.uid() | 013 |
| course_reviews | course_reviews_select | select | public | auth.uid() is not null |  | 013 |
| course_reviews | course_reviews_update | update | public | learner_id = auth.uid() | learner_id = auth.uid() | 013 |
| courses | courses_read | select | public | is_published or private.is_staff() or exists ( select 1 from public.enrollments e where e.course_id = courses.id and e.learner_id = (select auth.uid()) and e.deleted_at is null ) |  | 096 |
| courses | courses_write | all | public | public.is_staff() | public.is_staff() | 002 |
| department_members | department_members_select | select | public | public.is_staff() or user_id = auth.uid() |  | 040 |
| department_members | department_members_write | all | public | public.is_admin() | public.is_admin() | 040 |
| department_tasks | department_tasks_delete | delete | public | public.is_staff() or public.is_department_member(department_id) |  | 040 |
| department_tasks | department_tasks_insert | insert | public |  | public.is_staff() or public.is_department_member(department_id) | 040 |
| department_tasks | department_tasks_select | select | public | public.is_staff() or public.is_department_member(department_id) |  | 040 |
| department_tasks | department_tasks_update | update | public | public.is_staff() or public.is_department_member(department_id) | public.is_staff() or public.is_department_member(department_id) | 040 |
| departments | depts_select | select | public | public.is_staff() or organisation_id = (select organisation_id from public.profiles where id = auth.uid()) |  | 002 |
| departments | depts_write | all | public | public.is_admin() | public.is_admin() | 002 |
| email_campaigns | email_campaigns_insert | insert | public |  | public.is_staff() and created_by = auth.uid() | 008 |
| email_campaigns | email_campaigns_select | select | public | public.is_staff() |  | 008 |
| email_campaigns | email_campaigns_update | update | public | public.is_staff() | public.is_staff() | 008 |
| enrollments | enrollments_delete_staff | delete | public | private.staff_for(learner_id) |  | 092 |
| enrollments | enrollments_insert_own | insert | public |  | learner_id = auth.uid() or public.is_staff() | 022 |
| enrollments | enrollments_insert_staff | insert | public |  | private.staff_for(learner_id) | 092 |
| enrollments | enrollments_select | select | public | learner_id = (select auth.uid()) or private.staff_for(learner_id) |  | 092 |
| enrollments | enrollments_update_own | update | public | learner_id = auth.uid() | learner_id = auth.uid() | 002 |
| enrollments | enrollments_update_staff | update | public | private.staff_for(learner_id) | private.staff_for(learner_id) | 092 |
| feedback_responses | feedback_insert | insert | anon, authenticated |  | status = 'pending' | 058 |
| feedback_responses | feedback_select | select | public | (learner_id = auth.uid()) or public.is_staff() or (status = 'approved') |  | 039 |
| feedback_responses | feedback_update | update | public | public.is_staff() | public.is_staff() | 039 |
| forum_post_likes | forum_post_likes_delete | delete | public | user_id = auth.uid() |  | 038 |
| forum_post_likes | forum_post_likes_insert | insert | public |  | user_id = auth.uid() | 038 |
| forum_post_likes | forum_post_likes_select | select | public | auth.uid() is not null |  | 038 |
| forum_posts | forum_posts_delete | delete | public | author_id = auth.uid() or public.is_staff() |  | 007 |
| forum_posts | forum_posts_insert | insert | public |  | author_id = auth.uid() | 007 |
| forum_posts | forum_posts_select | select | public | auth.uid() is not null |  | 007 |
| forum_posts | forum_posts_update | update | public | author_id = auth.uid() or public.is_staff() | author_id = auth.uid() or public.is_staff() | 007 |
| forum_threads | forum_threads_delete | delete | public | created_by = auth.uid() or public.is_staff() |  | 007 |
| forum_threads | forum_threads_insert | insert | public |  | created_by = auth.uid() | 007 |
| forum_threads | forum_threads_select | select | public | auth.uid() is not null |  | 007 |
| forum_threads | forum_threads_update | update | public | created_by = auth.uid() or public.is_staff() | created_by = auth.uid() or public.is_staff() | 007 |
| invoices | invoices_select | select | public | recipient_id = auth.uid() or public.is_staff() |  | 028 |
| invoices | invoices_write | all | public | public.is_staff() | public.is_staff() | 028 |
| learner_certificates | certs_delete | delete | public | private.is_admin() and private.same_org(learner_id) |  | 092 |
| learner_certificates | certs_insert | insert | public |  | private.staff_for(learner_id) | 092 |
| learner_certificates | certs_select | select | public | learner_id = (select auth.uid()) or private.staff_for(learner_id) |  | 092 |
| learner_certificates | certs_update | update | public | private.is_admin() and private.same_org(learner_id) | private.is_admin() and private.same_org(learner_id) | 092 |
| learning_path_courses | lpc_select | select | public | auth.uid() is not null |  | 014 |
| learning_path_courses | lpc_write | all | public | public.is_staff() | public.is_staff() | 014 |
| learning_paths | learning_paths_select | select | public | is_published or public.is_staff() |  | 014 |
| learning_paths | learning_paths_write | all | public | public.is_staff() | public.is_staff() | 014 |
| lesson_progress | lesson_progress_delete_own | delete | public | learner_id = (select auth.uid()) |  | 092 |
| lesson_progress | lesson_progress_insert_own | insert | public |  | learner_id = (select auth.uid()) | 092 |
| lesson_progress | lesson_progress_select | select | public | learner_id = (select auth.uid()) or private.staff_for(learner_id) |  | 092 |
| lesson_progress | lesson_progress_update_own | update | public | learner_id = (select auth.uid()) | learner_id = (select auth.uid()) | 092 |
| lessons | lessons_read | select | public | auth.uid() is not null |  | 002 |
| lessons | lessons_write | all | public | public.is_staff() | public.is_staff() | 002 |
| mail_messages | mail_delete | delete | public | (owner_id = auth.uid()) or ((owner_id is null) and public.is_staff()) |  | 036 |
| mail_messages | mail_insert | insert | public |  | (owner_id = auth.uid()) or ((owner_id is null) and public.is_staff()) | 036 |
| mail_messages | mail_select | select | public | owner_id = auth.uid() or (owner_id is null and public.is_staff()) |  | 024 |
| mail_messages | mail_update | update | public | owner_id = auth.uid() or (owner_id is null and public.is_staff()) | owner_id = auth.uid() or (owner_id is null and public.is_staff()) | 024 |
| messages | messages_insert | insert | public |  | sender_id = auth.uid() | 002 |
| messages | messages_select | select | public | sender_id = auth.uid() or recipient_id = auth.uid() |  | 002 |
| messages | messages_update | update | public | recipient_id = auth.uid() | recipient_id = auth.uid() | 002 |
| modules | modules_read | select | public | auth.uid() is not null |  | 002 |
| modules | modules_write | all | public | public.is_staff() | public.is_staff() | 002 |
| notifications | notifications_insert | insert | public |  | public.is_staff() or user_id = auth.uid() | 002 |
| notifications | notifications_own | select | public | user_id = auth.uid() |  | 002 |
| notifications | notifications_update_own | update | public | user_id = auth.uid() | user_id = auth.uid() | 002 |
| one_to_one_requests | o2o_insert | insert | public |  | learner_id = auth.uid() | 019 |
| one_to_one_requests | o2o_select | select | public | learner_id = auth.uid() or trainer_id = auth.uid() or public.is_staff() |  | 019 |
| one_to_one_requests | o2o_update | update | public | public.is_staff() | public.is_staff() | 019 |
| order_items | order_items_insert | insert | public |  | private.is_staff() | 094 |
| order_items | order_items_select | select | public | exists ( select 1 from public.orders o where o.id = order_id and (o.buyer_id = auth.uid() or public.is_staff()) ) |  | 016 |
| orders | orders_insert | insert | public |  | private.is_staff() | 094 |
| orders | orders_select | select | public | buyer_id = auth.uid() or public.is_staff() |  | 016 |
| orders | orders_update | update | public | public.is_staff() | public.is_staff() | 016 |
| org_holidays | org_holidays_read | select | authenticated | true |  | 035 |
| org_holidays | org_holidays_write | all | authenticated | exists ( select 1 from public.profiles p where p.id = auth.uid() and p.role in ('admin', 'super_admin', 'manager', 'trainer') ) | exists ( select 1 from public.profiles p where p.id = auth.uid() and p.role in ('admin', 'super_admin', 'manager', 'trainer') ) | 035 |
| organisations | orgs_select | select | public | public.is_staff() or id = (select organisation_id from public.profiles where id = auth.uid()) |  | 002 |
| organisations | orgs_write | all | public | public.is_admin() | public.is_admin() | 002 |
| payroll | payroll_delete | delete | public | public.is_admin() |  | 031 |
| payroll | payroll_insert | insert | public |  | public.is_admin() | 031 |
| payroll | payroll_select | select | public | staff_id = auth.uid() or public.is_admin() |  | 031 |
| payroll | payroll_update | update | public | public.is_admin() | public.is_admin() | 031 |
| products | products_select | select | public | is_published or public.is_staff() |  | 016 |
| products | products_write | all | public | public.is_staff() | public.is_staff() | 016 |
| profiles | profiles_delete_admin | delete | public | public.is_admin() |  | 002 |
| profiles | profiles_insert_admin | insert | public |  | public.is_admin() | 002 |
| profiles | profiles_select | select | public | id = (select auth.uid()) or private.staff_for(id) |  | 092 |
| profiles | profiles_update_own | update | public | id = auth.uid() or public.is_admin() | id = auth.uid() or public.is_admin() | 002 |
| push_subscriptions | push_subs_all | all | public | user_id = auth.uid() | user_id = auth.uid() | 007 |
| question_options | options_read | select | public | private.is_staff() |  | 077 |
| question_options | options_write | all | public | public.is_staff() | public.is_staff() | 002 |
| questions | questions_read | select | public | auth.uid() is not null |  | 002 |
| questions | questions_write | all | public | public.is_staff() | public.is_staff() | 002 |
| recurring_templates | templates_all | all | public | public.is_staff() | public.is_staff() | 002 |
| reminders | reminders_all | all | public | user_id = auth.uid() | user_id = auth.uid() | 018 |
| resource_allocations | resource_allocations_self_read | select | authenticated | learner_id = auth.uid() |  | 034 |
| resource_allocations | resource_allocations_staff_all | all | authenticated | public.is_staff() or public.is_admin() | public.is_staff() or public.is_admin() | 034 |
| session_bookings | bookings_delete | delete | public | learner_id = auth.uid() or public.is_staff() |  | 002 |
| session_bookings | bookings_insert_own | insert | public |  | learner_id = auth.uid() or public.is_staff() | 002 |
| session_bookings | bookings_modify | update | public | learner_id = auth.uid() or public.is_staff() | learner_id = auth.uid() or public.is_staff() | 002 |
| session_bookings | bookings_select | select | public | learner_id = (select auth.uid()) or private.staff_for(learner_id) |  | 092 |
| session_join_requests | sjr_insert | insert | public |  | learner_id = auth.uid() | 076 |
| session_join_requests | sjr_learner_update | update | public | learner_id = auth.uid() | learner_id = auth.uid() and status = 'pending' | 087 |
| session_join_requests | sjr_select | select | public | learner_id = auth.uid() or private.is_staff() |  | 076 |
| session_join_requests | sjr_update | update | public | private.is_admin() | private.is_admin() | 076 |
| session_reminders | session_reminders_read | select | public | private.is_staff() |  | 089 |
| staff_training_records | str_rec_select | select | public | private.is_internal() |  | 061 |
| staff_training_records | str_rec_write | all | public | private.is_manager() | private.is_manager() | 061 |
| staff_training_requirements | str_req_select | select | public | private.is_internal() |  | 061 |
| staff_training_requirements | str_req_write | all | public | private.is_manager() | private.is_manager() | 061 |
| subscription_plans | plans_read | select | public | true |  | 002 |
| subscription_plans | plans_write | all | public | public.is_super_admin() | public.is_super_admin() | 002 |
| subscriptions | subs_select | select | public | public.is_admin() or organisation_id = (select organisation_id from public.profiles where id = auth.uid()) |  | 002 |
| subscriptions | subs_write | all | public | public.is_admin() | public.is_admin() | 002 |
| trainer_availability | trainer_avail_read | select | public | auth.uid() is not null |  | 002 |
| trainer_availability | trainer_avail_write | all | public | trainer_id = auth.uid() or public.is_admin() | trainer_id = auth.uid() or public.is_admin() | 002 |
| trainer_profiles | trainer_profiles_read | select | public | true |  | 002 |
| trainer_profiles | trainer_profiles_write | all | public | profile_id = auth.uid() or public.is_admin() | profile_id = auth.uid() or public.is_admin() | 002 |
| training_sessions | sessions_read | select | public | is_public or auth.uid() is not null |  | 002 |
| training_sessions | sessions_write | all | public | public.is_staff() | public.is_staff() | 002 |

## storage.objects policies

| Policy | Cmd | USING | WITH CHECK | Migration |
|---|---|---|---|---|
| course_media_write | insert |  | bucket_id = 'course-media' and public.is_staff() | 012 |
| course_media_update | update | bucket_id = 'course-media' and public.is_staff() |  | 012 |
| course_media_delete | delete | bucket_id = 'course-media' and public.is_staff() |  | 012 |
| files_select | select | bucket_id = 'files' and public.is_staff() |  | 029 |
| files_insert | insert |  | bucket_id = 'files' and public.is_staff() | 029 |
| files_delete | delete | bucket_id = 'files' and public.is_staff() |  | 029 |
| cert_sig_write | insert |  | bucket_id = 'certificate-signatures' and public.is_staff() | 033 |
| cert_sig_update | update | bucket_id = 'certificate-signatures' and public.is_staff() |  | 033 |
| cert_sig_delete | delete | bucket_id = 'certificate-signatures' and public.is_staff() |  | 033 |

## Helper functions (SECURITY DEFINER) the policies call

| Function | Policy references |
|---|---|
| public.is_staff | 94 |
| public.is_admin | 31 |
| private.staff_for | 20 |
| private.is_staff | 7 |
| public.is_department_member | 5 |
| private.is_admin | 5 |
| private.is_manager | 4 |
| public.is_super_admin | 3 |
| private.same_org | 3 |
| private.is_internal | 2 |

## All functions (71) and triggers (24)

Functions: private.current_organisation_id, private.default_organisation_id, private.is_admin, private.is_internal, private.is_manager, private.is_staff, private.is_super_admin, private.same_org, private.staff_for, public.approve_certificate, public.archive_course, public.confirm_order, public.course_deletion_impact, public.course_enrolment_counts, public.current_role_value, public.delete_course_permanently, public.gen_vc_code, public.get_assessment_review, public.get_public_config, public.get_question_options, public.get_session_join_link, public.google_oauth_status, public.guard_attendance_self_insert, public.guard_order_status, public.guard_profile_privileged_cols, public.handle_new_user, public.increment_blog_views, public.is_admin, public.is_department_member, public.is_staff, public.is_super_admin, public.issue_course_certificate, public.list_admin_contacts, public.next_certificate_number, public.next_order_reference, public.notify_expiring_certificates, public.notify_file_uploaded, public.notify_on_blog_post, public.notify_on_booking, public.notify_on_certificate, public.notify_on_completion, public.notify_on_dept_task, public.notify_on_enrolment, public.notify_on_feedback, public.notify_on_forum_post, public.notify_on_invoice, public.notify_on_message, public.notify_on_order, public.notify_on_payroll, public.notify_on_session_created, public.notify_on_trainer_assigned, public.notify_session_reminders, public.on_assessment_attempt_change, public.on_lesson_progress_change, public.place_order, public.profile_is_complete, public.push_on_notification, public.redeem_coupon, public.redeem_coupon_for_order, public.restore_course, public.search_contacts, public.set_order_status, public.set_updated_at, public.set_user_verification, public.set_verification_code, public.submit_assessment_attempt, public.sync_course_completion, public.toggle_blog_like, public.touch_department_task, public.touch_updated_at, public.verify_certificate

Triggers: course_resources_touch, guard_attendance_self_insert, guard_order_status, guard_profile_privileged_cols, on_auth_user_created, trg_assessment_attempt_completion, trg_lesson_progress_completion, trg_notify_blog_post, trg_notify_booking, trg_notify_certificate, trg_notify_completion, trg_notify_dept_task, trg_notify_enrolment, trg_notify_feedback, trg_notify_forum_post, trg_notify_invoice, trg_notify_message, trg_notify_order, trg_notify_payroll, trg_notify_session_created, trg_notify_trainer_assigned, trg_push_notification, trg_set_verification_code, trg_touch_department_task