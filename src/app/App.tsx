import { Route, Routes } from "react-router-dom";
import { AccountPage } from "@/features/account/AccountPage";
import { SignInPage } from "@/features/auth/SignInPage";
import { ContactPage } from "@/features/contact/ContactPage";
import { HomePage } from "@/features/site/HomePage";
import { NotFoundPage } from "@/features/site/NotFoundPage";
import { VerifyPage } from "@/features/verify/VerifyPage";
import { Layout } from "./Layout";

export function App() {
  return (
    <Routes>
      <Route element={<Layout />}>
        <Route index element={<HomePage />} />
        <Route path="verify" element={<VerifyPage />} />
        <Route path="verify/:code" element={<VerifyPage />} />
        <Route path="contact" element={<ContactPage />} />
        <Route path="sign-in" element={<SignInPage />} />
        <Route path="app" element={<AccountPage />} />
        <Route path="*" element={<NotFoundPage />} />
      </Route>
    </Routes>
  );
}
