import api from '@/lib/api';
export async function saveDamageReport(formData: FormData) {
  const res = await api.post("/damage-reports", formData, {
    headers: {
      "Content-Type": "multipart/form-data",
    },
  });

  return res.data;
}