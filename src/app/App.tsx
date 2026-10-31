import { Navigate, Route, Routes } from "react-router-dom";
import { AccountPage } from "@/features/account/AccountPage";
import { AdminLayout } from "@/features/admin/AdminLayout";
import {
  CertificatePage,
  CertificatesPage,
  RenewalsPage,
} from "@/features/admin/pages/Certificates";
import { CoursePage, CoursesPage } from "@/features/admin/pages/Courses";
import { DashboardPage, SearchPage } from "@/features/admin/pages/Dashboard";
import { EnquiriesPage, EnquiryPage, FollowUpsPage } from "@/features/admin/pages/Office";
import { AuditPage, ExportsPage, StaffAccountsPage } from "@/features/admin/pages/Owner";
import {
  OrganisationPage,
  OrganisationsPage,
  PeoplePage,
  PersonPage,
} from "@/features/admin/pages/People";
import { SessionPage, SessionsPage } from "@/features/admin/pages/Sessions";
import { SignInPage } from "@/features/auth/SignInPage";
import { ContactPage } from "@/features/contact/ContactPage";
import { CourseDetailPage, CourseListPage } from "@/features/courses/CoursePages";
import { HomePage } from "@/features/site/HomePage";
import { NotFoundPage } from "@/features/site/NotFoundPage";
import { VerifyPage } from "@/features/verify/VerifyPage";
import { Layout } from "./Layout";

export function App() {
  return (
    <Routes>
      <Route element={<Layout />}>
        <Route index element={<HomePage />} />
        <Route path="courses" element={<CourseListPage />} />
        <Route path="courses/:slug" element={<CourseDetailPage />} />
        <Route path="verify" element={<VerifyPage />} />
        <Route path="verify/:code" element={<VerifyPage />} />
        <Route path="contact" element={<ContactPage />} />
        <Route path="sign-in" element={<SignInPage />} />
        <Route path="app" element={<Navigate to="/admin" replace />} />
        <Route path="*" element={<NotFoundPage />} />
      </Route>
      {/* The office system (D-018): owner and office staff only. The API enforces this on every call. */}
      <Route path="admin" element={<AdminLayout />}>
        <Route index element={<DashboardPage />} />
        <Route path="search" element={<SearchPage />} />
        <Route path="people" element={<PeoplePage />} />
        <Route path="people/:id" element={<PersonPage />} />
        <Route path="organisations" element={<OrganisationsPage />} />
        <Route path="organisations/:id" element={<OrganisationPage />} />
        <Route path="courses" element={<CoursesPage />} />
        <Route path="courses/:id" element={<CoursePage />} />
        <Route path="sessions" element={<SessionsPage />} />
        <Route path="sessions/:id" element={<SessionPage />} />
        <Route path="certificates" element={<CertificatesPage />} />
        <Route path="certificates/:id" element={<CertificatePage />} />
        <Route path="renewals" element={<RenewalsPage />} />
        <Route path="enquiries" element={<EnquiriesPage />} />
        <Route path="enquiries/:id" element={<EnquiryPage />} />
        <Route path="follow-ups" element={<FollowUpsPage />} />
        <Route path="exports" element={<ExportsPage />} />
        <Route path="audit" element={<AuditPage />} />
        <Route path="staff" element={<StaffAccountsPage />} />
        <Route path="account" element={<AccountPage />} />
        <Route path="*" element={<NotFoundPage />} />
      </Route>
    </Routes>
  );
}
