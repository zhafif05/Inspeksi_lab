import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom';
import { Toaster } from 'sonner';

import './app/globals.css';

import AdminLayout from '@/app/admin/layout';
import AdminLogin from '@/app/admin/page';
import AdminDashboard from '@/app/admin/dashboard/page';
import AdminUsers from '@/app/admin/users/page';
import AdminLabs from '@/app/admin/labs/page';
import AdminLabDetail from '@/app/admin/labs/[id]/page';
import AdminInspection from '@/app/admin/labs/[id]/inspeksi/[itemId]/page';
import AdminItems from '@/app/admin/items/page';
import AdminProfile from '@/app/admin/profile/page';

import KalabLayout from '@/app/kalab/layout';
import KalabDashboard from '@/app/kalab/dashboard/page';
import KalabLabs from '@/app/kalab/labs/page';
import KalabLabDetail from '@/app/kalab/labs/[id]/page';
import KalabInspection from '@/app/kalab/labs/[id]/inspeksi/[itemId]/page';
import KalabProfile from '@/app/kalab/profile/page';

import PlpLayout from '@/app/plp/layout';
import PlpDashboard from '@/app/plp/dashboard/page';
import PlpLabs from '@/app/plp/labs/page';
import PlpLabDetail from '@/app/plp/labs/[id]/page';
import PlpInspection from '@/app/plp/labs/[id]/inspeksi/[itemId]/page';
import PlpProfile from '@/app/plp/profile/page';

import TeknisiLayout from '@/app/teknisi/layout';
import TeknisiDashboard from '@/app/teknisi/dashboard/page';
import TeknisiLabs from '@/app/teknisi/labs/page';
import TeknisiLabDetail from '@/app/teknisi/labs/[id]/page';
import TeknisiInspection from '@/app/teknisi/labs/[id]/inspeksi/[itemId]/page';
import TeknisiProfile from '@/app/teknisi/profile/page';

export default function App() {
  return (
    <BrowserRouter>
      <Routes>
        <Route path="/" element={<Navigate to="/admin" replace />} />

        <Route path="/admin" element={<AdminLayout />}>
          <Route index element={<AdminLogin />} />
          <Route path="dashboard" element={<AdminDashboard />} />
          <Route path="users" element={<AdminUsers />} />
          <Route path="labs" element={<AdminLabs />} />
          <Route path="labs/:id" element={<AdminLabDetail />} />
          <Route path="labs/:id/inspeksi/:itemId" element={<AdminInspection />} />
          <Route path="items" element={<AdminItems />} />
          <Route path="profile" element={<AdminProfile />} />
        </Route>

        <Route path="/kalab" element={<KalabLayout />}>
          <Route path="dashboard" element={<KalabDashboard />} />
          <Route path="labs" element={<KalabLabs />} />
          <Route path="labs/:id" element={<KalabLabDetail />} />
          <Route path="labs/:id/inspeksi/:itemId" element={<KalabInspection />} />
          <Route path="profile" element={<KalabProfile />} />
        </Route>

        <Route path="/plp" element={<PlpLayout />}>
          <Route path="dashboard" element={<PlpDashboard />} />
          <Route path="labs" element={<PlpLabs />} />
          <Route path="labs/:id" element={<PlpLabDetail />} />
          <Route path="labs/:id/inspeksi/:itemId" element={<PlpInspection />} />
          <Route path="profile" element={<PlpProfile />} />
        </Route>

        <Route path="/teknisi" element={<TeknisiLayout />}>
          <Route path="dashboard" element={<TeknisiDashboard />} />
          <Route path="labs" element={<TeknisiLabs />} />
          <Route path="labs/:id" element={<TeknisiLabDetail />} />
          <Route path="labs/:id/inspeksi/:itemId" element={<TeknisiInspection />} />
          <Route path="profile" element={<TeknisiProfile />} />
        </Route>

        <Route path="*" element={<Navigate to="/admin" replace />} />
      </Routes>
      <Toaster
        position="top-right"
        toastOptions={{
          style: {
            background: '#1E293B',
            color: '#fff',
            border: '1px solid rgba(255,255,255,0.1)',
          },
        }}
      />
    </BrowserRouter>
  );
}
