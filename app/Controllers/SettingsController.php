<?php
namespace App\Controllers;

use App\Core\ActivityLog;
use App\Core\Config;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Settings;
use App\Core\Validator;
use App\Services\KopoKopo;

class SettingsController extends Controller
{
    public function index(Request $request): void
    {
        $tab = (string) $request->query('tab', 'company');

        if (!in_array($tab, ['company', 'documents', 'payments', 'messaging', 'categories'], true)) {
            $tab = 'company';
        }

        $categories = Database::all(
            "SELECT c.*,
                    (SELECT COUNT(*) FROM inventory_items WHERE category_id = c.id) AS item_count,
                    (SELECT COUNT(*) FROM services WHERE category_id = c.id) AS service_count,
                    (SELECT COUNT(*) FROM expenses WHERE category_id = c.id) AS expense_count
               FROM categories c
           ORDER BY c.type, c.name"
        );

        $grouped = [];
        foreach ($categories as $c) {
            $grouped[$c['type']][] = $c;
        }

        $this->view('settings/index', [
            'title'          => 'Settings',
            'tab'            => $tab,
            'settings'       => Settings::company(),
            'categories'     => $grouped,
            'kopokopoReady'  => (new KopoKopo())->isConfigured(),
            'hasSecret'      => [
                'client_secret' => Settings::hasSecret('kopokopo_client_secret'),
                'api_key'       => Settings::hasSecret('kopokopo_api_key'),
                'smtp_password' => Settings::hasSecret('smtp_password'),
                'sms_api_key'   => Settings::hasSecret('sms_api_key'),
            ],
            'events'         => \App\Services\Notifier::EVENTS,
            'defaultCallback' => rtrim((string) Config::get('app.url', ''), '/') . base_path() . '/webhooks/kopokopo',
        ]);
    }

    public function saveCompany(Request $request): void
    {
        $v = new Validator($request->all());
        $v->require('company_name', 'Company name')
          ->maxLen('company_name', 180, 'Company name')
          ->email('company_email', 'Company email')
          ->phone('company_phone', 'Company phone')
          ->maxLen('company_address', 255, 'Address')
          ->maxLen('company_kra_pin', 30, 'KRA PIN')
          ->maxLen('currency', 5, 'Currency');

        if ($v->fails()) {
            $v->redirectBack('/settings');
        }

        Settings::setMany([
            'company_name'    => $request->input('company_name'),
            'company_tagline' => $request->input('company_tagline'),
            'company_email'   => $request->input('company_email'),
            'company_phone'   => $request->input('company_phone'),
            'company_address' => $request->input('company_address'),
            'company_website' => $request->input('company_website'),
            'company_kra_pin' => strtoupper((string) $request->input('company_kra_pin')),
            'currency'        => strtoupper((string) $request->input('currency', 'KES')),
        ]);

        // Logo appears on every printed document.
        $logo = $this->storeUpload($request->file('company_logo'), 'logos');
        if ($logo) {
            $this->deleteUpload(Settings::get('company_logo'));
            Settings::set('company_logo', $logo);
        }

        ActivityLog::record('settings_updated', 'settings', null, 'Updated company details');
        Session::success('Company details saved.');
        Response::to('/settings?tab=company');
    }

    public function saveDocuments(Request $request): void
    {
        $v = new Validator($request->all());
        $v->numeric('vat_rate', 'VAT rate')
          ->min('vat_rate', 0, 'VAT rate')
          ->in('vat_default_mode', ['exclusive', 'inclusive', 'exempt'], 'Default VAT treatment')
          ->numeric('quotation_validity_days', 'Quotation validity')
          ->min('quotation_validity_days', 1, 'Quotation validity')
          ->numeric('invoice_due_days', 'Invoice payment terms')
          ->min('invoice_due_days', 0, 'Invoice payment terms');

        if ($request->decimal('vat_rate') > 100) {
            $v->custom('vat_rate', false, 'VAT rate cannot exceed 100%.');
        }

        foreach (['quotation_prefix', 'invoice_prefix', 'receipt_prefix', 'payment_prefix', 'expense_prefix', 'lead_prefix', 'client_prefix'] as $field) {
            $v->maxLen($field, 10, label_of($field));
        }

        if ($v->fails()) {
            $v->redirectBack('/settings?tab=documents');
        }

        Settings::setMany([
            'vat_rate'                => $request->decimal('vat_rate', 16),
            'vat_default_mode'        => $request->input('vat_default_mode'),
            'quotation_validity_days' => $request->int('quotation_validity_days', 30),
            'invoice_due_days'        => $request->int('invoice_due_days', 14),
            'quotation_prefix'        => strtoupper((string) $request->input('quotation_prefix', 'QTN')),
            'invoice_prefix'          => strtoupper((string) $request->input('invoice_prefix', 'INV')),
            'receipt_prefix'          => strtoupper((string) $request->input('receipt_prefix', 'RCP')),
            'payment_prefix'          => strtoupper((string) $request->input('payment_prefix', 'PMT')),
            'expense_prefix'          => strtoupper((string) $request->input('expense_prefix', 'EXP')),
            'lead_prefix'             => strtoupper((string) $request->input('lead_prefix', 'LD')),
            'client_prefix'           => strtoupper((string) $request->input('client_prefix', 'CL')),
            'quotation_terms'         => $request->input('quotation_terms'),
            'invoice_terms'           => $request->input('invoice_terms'),
            'bank_details'            => $request->input('bank_details'),
            'mpesa_till'              => $request->input('mpesa_till'),
        ]);

        ActivityLog::record('settings_updated', 'settings', null, 'Updated document settings');
        Session::success('Document settings saved.');
        Response::to('/settings?tab=documents');
    }

    public function savePayments(Request $request): void
    {
        $v = new Validator($request->all());
        $v->in('kopokopo_env', ['sandbox', 'production'], 'Environment')
          ->maxLen('kopokopo_client_id', 255, 'Client ID')
          ->maxLen('kopokopo_till_number', 40, 'Till number');

        $enabled = $request->bool('kopokopo_enabled');

        // Enabling needs a full credential set and an HTTPS callback.
        if ($enabled) {
            $clientId = trim((string) $request->input('kopokopo_client_id', ''));
            $till     = trim((string) $request->input('kopokopo_till_number', ''));

            $secretProvided = trim((string) $request->input('kopokopo_client_secret', '')) !== ''
                || Settings::hasSecret('kopokopo_client_secret');
            $apiKeyProvided = trim((string) $request->input('kopokopo_api_key', '')) !== ''
                || Settings::hasSecret('kopokopo_api_key');

            if ($clientId === '')     { $v->custom('kopokopo_client_id', false, 'Client ID is required to enable KopoKopo.'); }
            if (!$secretProvided)     { $v->custom('kopokopo_client_secret', false, 'Client Secret is required to enable KopoKopo.'); }
            if ($till === '')         { $v->custom('kopokopo_till_number', false, 'Till number is required to enable KopoKopo.'); }
            if (!$apiKeyProvided)     { $v->custom('kopokopo_api_key', false, 'The API key is required — it verifies webhook signatures.'); }

            $callback = trim((string) $request->input('kopokopo_callback_url', ''));
            if ($callback !== '' && !str_starts_with($callback, 'https://')) {
                $v->custom('kopokopo_callback_url', false, 'The callback URL must use HTTPS.');
            }
        }

        if ($v->fails()) {
            $v->redirectBack('/settings?tab=payments');
        }

        $updates = [
            'kopokopo_enabled'     => $enabled ? '1' : '0',
            'kopokopo_env'         => $request->input('kopokopo_env', 'sandbox'),
            'kopokopo_client_id'   => trim((string) $request->input('kopokopo_client_id', '')),
            'kopokopo_till_number' => trim((string) $request->input('kopokopo_till_number', '')),
            'kopokopo_callback_url'=> trim((string) $request->input('kopokopo_callback_url', '')),
        ];

        // Blank secret fields mean "leave the stored value alone".
        $secret = trim((string) $request->input('kopokopo_client_secret', ''));
        if ($secret !== '') {
            $updates['kopokopo_client_secret'] = $secret;
        }

        $apiKey = trim((string) $request->input('kopokopo_api_key', ''));
        if ($apiKey !== '') {
            $updates['kopokopo_api_key'] = $apiKey;
        }

        Settings::setMany($updates);

        // Credentials changed, so any cached access token is stale.
        Settings::set('kopokopo_token_cache', '');
        Settings::flush();

        ActivityLog::record(
            'settings_updated',
            'settings',
            null,
            'Updated KopoKopo settings (' . ($enabled ? 'enabled' : 'disabled') . ')'
        );

        Session::success('Payment settings saved.' . ($enabled ? ' Test the connection below.' : ''));
        Response::to('/settings?tab=payments');
    }

    /** Ask KopoKopo for a token, to prove the credentials work. */
    public function testKopokopo(Request $request): void
    {
        $kopokopo = new KopoKopo();

        if (!$kopokopo->isConfigured()) {
            Session::error('Enter your Client ID, Client Secret and Till number first.');
            Response::to('/settings?tab=payments');
        }

        $result = $kopokopo->token(true);

        if ($result['ok']) {
            Session::success(
                'Connected to KopoKopo (' . Settings::get('kopokopo_env', 'sandbox') . ') successfully. '
                . 'Access token received.'
            );
        } else {
            Session::error('KopoKopo connection failed: ' . $result['error']);
        }

        Response::to('/settings?tab=payments');
    }

    /** Register our callback URL with KopoKopo so webhooks start arriving. */
    public function subscribeWebhook(Request $request): void
    {
        $kopokopo = new KopoKopo();

        if (!$kopokopo->isConfigured()) {
            Session::error('Configure your KopoKopo credentials first.');
            Response::to('/settings?tab=payments');
        }

        $callback = (string) Settings::get('kopokopo_callback_url', '');

        if ($callback === '') {
            $callback = rtrim((string) Config::get('app.url', ''), '/') . base_path() . '/webhooks/kopokopo';
        }

        if (!str_starts_with($callback, 'https://')) {
            Session::error('The callback URL must be a public HTTPS address before KopoKopo will accept it.');
            Response::to('/settings?tab=payments');
        }

        $result = $kopokopo->subscribeWebhook('buygoods_transaction_received', $callback);

        if ($result['ok']) {
            ActivityLog::record('kopokopo_webhook_subscribed', 'settings', null, 'Subscribed ' . $callback);
            Session::success('Webhook registered with KopoKopo for ' . $callback);
        } else {
            Session::error('Could not register the webhook: ' . $result['error']);
        }

        Response::to('/settings?tab=payments');
    }

    public function saveMessaging(Request $request): void
    {
        $v = new Validator($request->all());
        $v->in('smtp_encryption', ['tls', 'ssl', 'none'], 'Encryption')
          ->email('smtp_from_email', 'From address')
          ->email('smtp_reply_to', 'Reply-to address')
          ->maxLen('smtp_host', 180, 'SMTP host')
          ->maxLen('smtp_from_name', 120, 'From name')
          ->maxLen('sms_sender_id', 20, 'Sender ID')
          ->numeric('notify_max_attempts', 'Retry attempts');

        $emailOn = $request->bool('smtp_enabled');
        $smsOn   = $request->bool('sms_enabled');

        if ($emailOn) {
            if (trim((string) $request->input('smtp_host', '')) === '') {
                $v->custom('smtp_host', false, 'The SMTP host is required to enable email.');
            }
            if (trim((string) $request->input('smtp_from_email', '')) === '') {
                $v->custom('smtp_from_email', false, 'A from address is required to enable email.');
            }
        }

        if ($smsOn) {
            if (trim((string) $request->input('sms_client_id', '')) === '') {
                $v->custom('sms_client_id', false, 'The Client ID from Shanfix Bulk SMS is required to enable SMS.');
            }
            if (trim((string) $request->input('sms_api_key', '')) === '' && !Settings::hasSecret('sms_api_key')) {
                $v->custom('sms_api_key', false, 'The API key is required to enable SMS.');
            }
            if (trim((string) $request->input('sms_sender_id', '')) === '') {
                $v->custom('sms_sender_id', false, 'A sender ID approved on your Shanfix Bulk SMS account is required.');
            }
        }

        $smsBase = trim((string) $request->input('sms_base_url', ''));
        if ($smsBase !== '' && !str_starts_with($smsBase, 'https://')) {
            $v->custom('sms_base_url', false, 'The SMS portal address must start with https://');
        }

        $window = trim((string) $request->input('notify_send_window', ''));
        if ($window !== '' && !preg_match('/^\d{1,2}:\d{2}\s*-\s*\d{1,2}:\d{2}$/', $window)) {
            $v->custom('notify_send_window', false, 'Use the format 08:00-18:00, or leave it blank to send any time.');
        }

        $days = trim((string) $request->input('notify_overdue_days', ''));
        if ($days !== '' && !preg_match('/^\d+(\s*,\s*\d+)*$/', $days)) {
            $v->custom('notify_overdue_days', false, 'Enter day numbers separated by commas, e.g. 1,7,14');
        }

        // The two look-ahead windows take the same comma-separated form.
        $lookAhead = [];
        foreach (['notify_due_days', 'notify_expiry_days'] as $field) {
            $value = trim((string) $request->input($field, ''));

            if ($value !== '' && !preg_match('/^\d+(\s*,\s*\d+)*$/', $value)) {
                $v->custom($field, false, 'Enter day numbers separated by commas, e.g. 3,1');
            }

            $lookAhead[$field] = $value === '' ? '' : preg_replace('/\s+/', '', $value);
        }

        if ($v->fails()) {
            $v->redirectBack('/settings?tab=messaging');
        }

        $updates = [
            'smtp_enabled'    => $emailOn ? '1' : '0',
            'smtp_host'       => trim((string) $request->input('smtp_host', '')),
            'smtp_port'       => $request->int('smtp_port', 587),
            'smtp_encryption' => (string) $request->input('smtp_encryption', 'tls'),
            'smtp_username'   => trim((string) $request->input('smtp_username', '')),
            'smtp_from_email' => trim((string) $request->input('smtp_from_email', '')),
            'smtp_from_name'  => trim((string) $request->input('smtp_from_name', '')),
            'smtp_reply_to'   => trim((string) $request->input('smtp_reply_to', '')),

            'sms_enabled'     => $smsOn ? '1' : '0',
            'sms_provider'    => 'shanfix',
            'sms_client_id'   => trim((string) $request->input('sms_client_id', '')),
            'sms_sender_id'   => trim((string) $request->input('sms_sender_id', '')),
            'sms_base_url'    => $smsBase !== '' ? rtrim($smsBase, '/') : \App\Services\Sms::DEFAULT_BASE_URL,

            'notify_overdue_days' => $days !== '' ? preg_replace('/\s+/', '', $days) : '1,7,14',
            'notify_due_days'     => $lookAhead['notify_due_days'],
            'notify_expiry_days'  => $lookAhead['notify_expiry_days'],
            'notify_send_window'  => $window,
            'notify_max_attempts' => max(1, min(10, $request->int('notify_max_attempts', 3))),

            'email_footer_note' => (string) $request->input('email_footer_note', ''),
        ];

        // Blank secret fields mean "keep what is stored".
        $smtpPassword = (string) $request->input('smtp_password', '');
        if (trim($smtpPassword) !== '') {
            $updates['smtp_password'] = $smtpPassword;
        }

        $smsKey = trim((string) $request->input('sms_api_key', ''));
        if ($smsKey !== '') {
            $updates['sms_api_key'] = $smsKey;
        }

        // Per-event channel toggles
        foreach (array_keys(\App\Services\Notifier::EVENTS) as $event) {
            $updates["notify_{$event}_email"] = $request->bool("notify_{$event}_email") ? '1' : '0';
            $updates["notify_{$event}_sms"]   = $request->bool("notify_{$event}_sms") ? '1' : '0';
        }

        // Templates
        foreach ($request->all() as $key => $value) {
            if (str_starts_with((string) $key, 'tpl_') && is_string($value)) {
                $updates[$key] = $value;
            }
        }

        Settings::setMany($updates);
        Settings::flush();

        ActivityLog::record(
            'settings_updated',
            'settings',
            null,
            'Updated messaging settings (email ' . ($emailOn ? 'on' : 'off')
            . ', SMS ' . ($smsOn ? 'on' : 'off') . ')'
        );

        Session::success('Messaging settings saved. Send yourself a test to confirm they work.');
        Response::to('/settings?tab=messaging');
    }

    // -- Categories ----------------------------------------------------

    public function storeCategory(Request $request): void
    {
        $v = new Validator($request->all());
        $v->require('name', 'Category name')
          ->maxLen('name', 120, 'Category name')
          ->in('type', ['inventory', 'service', 'expense'], 'Category type');

        if ($v->fails()) {
            $v->redirectBack('/settings?tab=categories');
        }

        $exists = Database::first(
            'SELECT id FROM categories WHERE name = :name AND type = :type',
            ['name' => $request->input('name'), 'type' => $request->input('type')]
        );

        if ($exists) {
            Session::warning('That category already exists.');
            Response::to('/settings?tab=categories');
        }

        Database::insert('categories', [
            'name' => (string) $request->input('name'),
            'type' => (string) $request->input('type'),
        ]);

        Session::success('Category added.');
        Response::to('/settings?tab=categories');
    }

    public function destroyCategory(Request $request): void
    {
        $id = $request->paramInt('id');

        $category = Database::first('SELECT * FROM categories WHERE id = :id', ['id' => $id]);

        if (!$category) {
            Session::error('That category does not exist.');
            Response::to('/settings?tab=categories');
        }

        // Foreign keys are ON DELETE SET NULL, so records survive; warn anyway.
        $inUse = (int) Database::scalar(
            'SELECT (SELECT COUNT(*) FROM inventory_items WHERE category_id = :id1)
                  + (SELECT COUNT(*) FROM services WHERE category_id = :id2)
                  + (SELECT COUNT(*) FROM expenses WHERE category_id = :id3)',
            ['id1' => $id, 'id2' => $id, 'id3' => $id],
            0
        );

        Database::delete('categories', ['id' => $id]);

        if ($inUse > 0) {
            Session::warning(
                'Category deleted. ' . $inUse . ' record(s) that used it are now uncategorised.'
            );
        } else {
            Session::success('Category deleted.');
        }

        Response::to('/settings?tab=categories');
    }
}
