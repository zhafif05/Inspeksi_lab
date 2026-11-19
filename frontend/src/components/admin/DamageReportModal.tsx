import { CustomSelect } from "@/components/admin/CustomSelect_kalab";
import { X, Upload } from "lucide-react";

type DamageForm = {
  kondisi_kerusakan: string;
  penyebab_kerusakan: string;
  status_peralatan: string;
  foto: File | null;
};

interface Props {
  open: boolean;
  onClose: () => void;

  selected: {
    category_id: number;
    subitem_id: number;
    namaKategori: string;
    namaSubitem: string;
  } | null;

  damageReports?: Record<
    number,
    DamageForm
  >;

  setDamageReports?: React.Dispatch<
    React.SetStateAction<
      Record<number, DamageForm>
    >
  >;

  value?: DamageForm;
  setValue?: React.Dispatch<React.SetStateAction<DamageForm>>;
}



export default function DamageReportModal({
  open,
  onClose,
  selected,
  damageReports,
  setDamageReports,
  value: singleValue,
  setValue,
}: Props) {
  if (!open) return null;

  const emptyValue: DamageForm = {
    kondisi_kerusakan: "",
    penyebab_kerusakan: "",
    status_peralatan: "MASIH_DIGUNAKAN",
    foto: null,
  };
  const value = singleValue ?? (selected && damageReports
    ? damageReports[selected.subitem_id] ?? {
      kondisi_kerusakan: "",
      penyebab_kerusakan: "",
      status_peralatan: "MASIH_DIGUNAKAN",
      foto: null,
    }
    : emptyValue);

  const updateValue = (nextValue: DamageForm) => {
    if (setValue) {
      setValue(nextValue);
      return;
    }

    if (!selected || !setDamageReports) return;
    setDamageReports((prev) => ({
      ...prev,
      [selected.subitem_id]: nextValue,
    }));
  };

  const handleSave = () => {
    if (!selected) return;
    updateValue(value);
    onClose();
  };

  return (
    <div className="fixed inset-0 z-[999] bg-black/60 backdrop-blur-sm flex items-center justify-center p-4">

      <div className="w-full max-w-2xl rounded-2xl bg-[#0F172A] border border-white/10 shadow-2xl">

        {/* Header */}
        <div className="flex items-center justify-between px-6 py-4 border-b border-white/10">
          <div>
            <h2 className="text-lg font-bold text-white">
              Laporan Kerusakan
            </h2>

            <p className="text-xs text-white/40 mt-1">
              {selected?.namaKategori} • {selected?.namaSubitem}
            </p>
          </div>

          <button
            onClick={onClose}
            className="p-2 rounded-lg hover:bg-white/5"
          >
            <X className="w-5 h-5 text-white/60" />
          </button>
        </div>

        {/* Body */}
        <div className="p-6 space-y-5">

          {/* Kondisi */}
          <div>
            <label className="block text-sm text-white/70 mb-2">
              Kondisi Kerusakan
            </label>

            <textarea
              rows={3}
              value={value.kondisi_kerusakan}
              onChange={(e) =>
                updateValue({
                  ...value,
                  kondisi_kerusakan: e.target.value,
                })
              }
              placeholder="Masukkan kondisi kerusakan..."
              className="w-full rounded-xl bg-white/5 border border-white/10 p-3 text-white placeholder:text-white/30 resize-none"
            />
          </div>

          {/* Penyebab */}
          <div>
            <label className="block text-sm text-white/70 mb-2">
              Penyebab Kerusakan
            </label>

            <textarea
              rows={3}
              value={value.penyebab_kerusakan}
              onChange={(e) =>
                updateValue({
                  ...value,
                  penyebab_kerusakan: e.target.value,
                })
              }
              placeholder="Masukkan penyebab kerusakan..."
              className="w-full rounded-xl bg-white/5 border border-white/10 p-3 text-white placeholder:text-white/30 resize-none"
            />
          </div>

          {/* Status */}
          <div>
            <label className="block text-sm text-white/70 mb-2">
              Status Peralatan
            </label>

            <CustomSelect
              value={value.status_peralatan}
              onChange={(v) =>
                updateValue({
                  ...value,
                  status_peralatan: v,
                })
              }
              placeholder="Pilih Status"
              options={[
                {
                  value: "MASIH_DIGUNAKAN",
                  label: "Masih Digunakan",
                },
                {
                  value: "DALAM_PERBAIKAN",
                  label: "Dalam Perbaikan",
                },
                {
                  value: "MENUNGGU_PENGADAAN",
                  label: "Menunggu Pengadaan",
                },
              ]}
            />
          </div>

          {/* Foto */}
          <div>

            <label className="block text-sm text-white/70 mb-2">
              Foto Kerusakan
            </label>

            <label className="flex items-center gap-3 rounded-xl border border-dashed border-white/15 p-4 cursor-pointer hover:border-blue-400 transition">

              <Upload className="w-5 h-5 text-white/40" />

              <span className="text-sm text-white/40">
                {value.foto ? value.foto.name : "Upload Foto"}
              </span>

              <input
                type="file"
                accept="image/*"
                hidden
                onChange={(e) =>
                  updateValue({
                    ...value,
                    foto: e.target.files?.[0] || null,
                  })
                }
              />

            </label>

          </div>

        </div>

        {/* Footer */}

        <div className="flex justify-end gap-3 border-t border-white/10 px-6 py-4">

          <button
            onClick={onClose}
            className="px-5 py-2 rounded-xl border border-white/10 text-white/60 hover:bg-white/5"
          >
            Batal
          </button>

          <button
            onClick={handleSave}
            className="px-5 py-2 rounded-xl bg-blue-500 hover:bg-blue-600 text-white font-semibold"
          >
            Simpan
          </button>

        </div>

      </div>

    </div>
  );
}
