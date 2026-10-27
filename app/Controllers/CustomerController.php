<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Security\Auth;
use App\Services\ActivityLogger;
use App\Services\CustomerDeletionService;

final class CustomerController extends PartyController
{
    protected function type(): string
    {
        return 'customer';
    }

    /** Suppression (administrateur) : inventaire de tout ce qui est rattaché au client, puis double saisie du mot de passe. */
    public function confirmDelete(Request $request): Response
    {
        $this->authorize('parties.delete');
        $customer = $this->orNotFound($this->repo()->find($request->intParam('id')));
        return $this->view('parties/delete', [
            'title' => t('customers.delete.title', ['name' => $customer['name']]),
            'party' => $customer,
            'impact' => (new CustomerDeletionService())->impact((int) $customer['id']),
        ]);
    }

    public function destroy(Request $request): Response
    {
        $this->authorize('parties.delete');
        $customer = $this->orNotFound($this->repo()->find($request->intParam('id')));
        $id = (int) $customer['id'];
        $input = $request->all();
        $password = (string) ($input['password'] ?? '');
        $back = "/customers/$id/delete";

        if ($password === '' || $password !== (string) ($input['password_confirm'] ?? '')) {
            return $this->backWithErrors($back, ['password_confirm' => t('validation.password_mismatch')], [], t('customers.delete.password_mismatch'));
        }
        $stmt = Database::pdo()->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([Auth::require()->id]);
        if (!password_verify($password, (string) $stmt->fetchColumn())) {
            ActivityLogger::log('customer', $id, 'delete_denied', null, ['reason' => 'wrong_password']);
            return $this->backWithErrors($back, ['password' => t('customers.delete.wrong_password')], [], t('customers.delete.wrong_password'));
        }

        $before = array_intersect_key($customer, array_flip(['id', 'code', 'name', 'rccm', 'id_nat', 'nif', 'address', 'city', 'country',
            'contact_name', 'phone', 'email', 'payment_terms_days', 'currency', 'notes', 'active']));
        (new CustomerDeletionService())->delete($before);
        return $this->success('/customers', 'customers.delete.done', ['name' => $customer['name']]);
    }
}
