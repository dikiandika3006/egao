<?php

namespace App\Livewire\Modules;

use App\Livewire\Concerns\ModulePage;
use App\Models\Clinic;
use App\Support\About;
use App\Support\BranchContext;
use App\Support\Faq;
use Livewire\Attributes\Url;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * Tentang aplikasi: info aplikasi, aturan sistem, riwayat perubahan. Terbuka untuk semua peran.
 */
class Tentang extends Component
{
    use ModulePage;

    public const TABS = ['aplikasi' => 'Tentang & aturan', 'panduan' => 'Panduan & FAQ'];

    #[Url(except: 'aplikasi')]
    public string $tab = 'aplikasi';

    #[Url(as: 'cari', except: '')]
    public string $search = '';

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
            'lastUpdate' => About::CHANGELOG[0]['date'] ?? '-',
            'platform' => 'Laravel '.app()->version().' · PHP '.PHP_VERSION,
            'database' => DB::connection()->getDriverName(),
            'tabs' => self::TABS,
            'faqs' => $faqs,
            'faqCount' => $faqs->flatten(1)->count(),
            'rules' => About::RULES,
            'changelog' => About::CHANGELOG,
        ]);
    }
}
