<?php

namespace App\Livewire\Modules;

use App\Enums\Role;
use App\Livewire\Concerns\ModulePage;
use App\Models\AuditLog;
use App\Models\Clinic;
use App\Support\About;
use App\Support\BranchContext;
use App\Support\Faq;
use App\Support\Manual;
use Livewire\Attributes\Url;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Tentang aplikasi: info aplikasi, aturan sistem, riwayat perubahan. Terbuka untuk semua peran.
 */
class Tentang extends Component
{
    use ModulePage, WithFileUploads;

    public const MENU = 'tentang';

    /** Boleh tanpa izin Edit (lihat ModulePage). */
    public const READ_ACTIONS = ['setTab'];

    public const TABS = ['aplikasi' => 'Tentang & aturan', 'panduan' => 'Panduan & FAQ'];

    #[Url(except: 'aplikasi')]
    public string $tab = 'aplikasi';

    #[Url(as: 'cari', except: '')]
    public string $search = '';

    /** Formulir unggah buku manual (khusus Owner): peran tujuan + file PDF. */
    public string $manualRole = 'admin';

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $manualFile = null;

    public function mount(): void
    {
        // Tautan pencarian langsung (?cari=gaji) membuka tab panduan.
        if ($this->search !== '') {
            $this->tab = 'panduan';
        }
    }

    public function setTab(string $tab): void
    {
        abort_unless(array_key_exists($tab, self::TABS), 404);
        $this->tab = $tab;
    }

    /** Owner mengunggah / mengganti buku manual PDF satu peran (izin Edit menu Tentang terbuka untuk semua, jadi dicek di sini). */
    public function saveManual(BranchContext $context): void
    {
        abort_unless($context->is(Role::Owner), 403, 'Hanya Owner yang dapat mengganti buku manual.');

        $this->validate([
            'manualRole' => ['required', \Illuminate\Validation\Rule::enum(Role::class)],
            'manualFile' => ['required', 'file', 'mimes:pdf', 'max:10240'],
        ], [
            'manualRole.*' => 'Pilih peran manual.',
            'manualFile.required' => 'Pilih file PDF terlebih dulu.',
            'manualFile.mimes' => 'File harus berformat PDF.',
            'manualFile.max' => 'Ukuran file maksimal 10 MB.',
        ]);

        $role = Role::from($this->manualRole);
        $this->manualFile->storeAs(Manual::DIR, $role->value.'.pdf', 'local');
        $this->reset('manualFile');

        AuditLog::record('manual.uploaded', null, ['role' => $role->value]);
        $this->toast('Buku manual '.$role->label().' tersimpan.');
    }

    public function render(BranchContext $context)
    {
        if (! array_key_exists($this->tab, self::TABS)) {
            $this->tab = 'aplikasi';
        }

        $faqs = collect(Faq::search($this->search))->groupBy('group');

        $clinic = Clinic::find($this->clinicId());

        return $this->page('tentang', 'livewire.modules.tentang', [
            'clinic' => $clinic,
            'tagline' => Settings::get('clinic_tagline'),
            'branches' => $context->options(),
            'version' => About::VERSION,
            'lastUpdate' => ($at = About::updatedAt()) ? $at->translatedFormat('j F Y, H.i').' WIB' : (About::CHANGELOG[0]['date'] ?? '-'),
            'platform' => 'Laravel '.app()->version().' · PHP '.PHP_VERSION,
            'database' => DB::connection()->getDriverName(),
            'tabs' => self::TABS,
            'manuals' => array_map(fn (Role $r) => Manual::info($r), Manual::visibleFor($context->role())),
            'isOwner' => $context->is(Role::Owner),
            'faqs' => $faqs,
            'faqCount' => $faqs->flatten(1)->count(),
            'rules' => About::RULES,
            'changelog' => About::CHANGELOG,
        ]);
    }
}
