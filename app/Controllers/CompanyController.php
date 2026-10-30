<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Domain\DomainException;
use App\Services\ActivityLogger;
use App\Services\CompanySettings;

/** Administration → Société : identité imprimée sur les documents (coordonnées, identifiants légaux, logo). */
final class CompanyController extends Controller
{
    public function edit(Request $request): Response
    {
        $this->authorize('settings.company');
        return $this->view('company/edit', [
            'title' => t('nav.company'),
            'company' => CompanySettings::stored(),
            'hasLogo' => CompanySettings::get()['has_logo'],
            'logoMaxKb' => CompanySettings::LOGO_MAX_BYTES / 1024,
        ]);
    }

    public function update(Request $request): Response
    {
        $this->authorize('settings.company');
        $input = $request->all();
        $v = new Validator($input, [
            'name' => 'required|max:190', 'legal_form' => 'max:60', 'address' => 'max:255', 'city' => 'max:120', 'country' => 'max:80',
            'phone' => 'max:60', 'email' => 'email|max:190', 'website' => 'max:190', 'rccm' => 'max:60', 'id_nat' => 'max:60',
            'nif' => 'max:60', 'vat_number' => 'max:60', 'importer_number' => 'max:60', 'share_capital' => 'max:60',
            'bank_details' => 'max:1000', 'invoice_terms' => 'max:1000', 'vat_rate' => 'numeric|min:0|max:6',
        ]);
        if (is_numeric($input['vat_rate'] ?? '') && (float) $input['vat_rate'] > 100) {
            $v->addError('vat_rate', t('invoice.error.vat_rate'));
        }
        if ($v->fails()) {
            return $this->backWithErrors('/settings/company', $v->errors(), $input);
        }
        $data = [];
        foreach (CompanySettings::FIELDS as $f) {
            $data[$f] = self::nul(trim((string) ($input[$f] ?? '')));
        }
        $hasFile = ($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        try {
            Database::transaction(function () use ($data, $hasFile) {
                $before = CompanySettings::stored();
                CompanySettings::save($data);
                if ($hasFile) {
                    CompanySettings::saveLogo($_FILES['logo']);
                }
                ActivityLogger::log('company', 1, 'update', $before, $data + ($hasFile ? ['logo' => 'replaced'] : []));
            });
        } catch (DomainException $e) {
            return $this->backWithErrors('/settings/company', ['logo' => t($e->getMessage(), $e->params)], $input);
        }
        return $this->success('/settings/company', 'flash.updated');
    }

    public function deleteLogo(Request $request): Response
    {
        $this->authorize('settings.company');
        Database::transaction(function () {
            CompanySettings::deleteLogo();
            ActivityLogger::log('company', 1, 'update', null, ['logo' => 'deleted']);
        });
        return $this->success('/settings/company', 'company.logo_deleted');
    }

    /** Logo (barre latérale, aperçu) : tout utilisateur connecté, mis en cache par le navigateur. */
    public function logo(Request $request): Response
    {
        $this->user();
        $logo = CompanySettings::logo() ?? throw new HttpException(404);
        return new Response($logo[1], 200, [
            'Content-Type' => $logo[0],
            'Cache-Control' => 'private, max-age=86400',
            'ETag' => '"' . $logo[2] . '"',
        ]);
    }
}
