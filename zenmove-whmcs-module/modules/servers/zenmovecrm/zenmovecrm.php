<?php
/**
 * ZenMove CRM — WHMCS Provisioning Module
 *
 * Allows resellers to sell ZenMove CRM instances (Starter / Growth / Pro)
 * directly from their own WHMCS billing panel.
 *
 * Each WHMCS order maps to one provisioning_jobs row on the ZenMove platform.
 * Lifecycle (suspend/unsuspend/terminate/upgrade) is handled entirely via the
 * ZenMove Reseller API, keyed by WHMCS service ID.
 *
 * Compatible with WHMCS 8.x
 *
 * FILE: <whmcs_root>/modules/servers/zenmovecrm/zenmovecrm.php
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly.');
}

require_once __DIR__ . '/lib/ZenMoveClient.php';

/* ══════════════════════════════════════════════════════════════════════
 * CONSTANTS
 * ══════════════════════════════════════════════════════════════════════ */

define('ZENMOVE_MODULE_VERSION', '1.0.0');

const ZENMOVE_VALID_PLANS = [
    'crm_starter' => 'Starter CRM ($99/mo)',
    'crm_growth'  => 'Growth CRM ($249/mo)',
    'crm_pro'     => 'Pro CRM ($500/mo)',
];

/* ══════════════════════════════════════════════════════════════════════
 * MODULE METADATA
 * ══════════════════════════════════════════════════════════════════════ */

/**
 * Module metadata shown in the WHMCS admin server module list.
 */
function zenmovecrm_MetaData(): array
{
    return [
        'DisplayName'               => 'ZenMove CRM',
        'APIVersion'                => '1.1',
        'RequiresServer'            => true,
        'DefaultNonSSLPort'         => '443',
        'DefaultSSLPort'            => '443',
        'ServiceSingleSignOnLabel'  => 'Open CRM Dashboard',
        'ListAccountsUnsupported'   => true,
    ];
}

/* ══════════════════════════════════════════════════════════════════════
 * SERVER / PRODUCT CONFIGURATION
 * ══════════════════════════════════════════════════════════════════════ */

/**
 * Fields shown on the Server Group configuration page in WHMCS Admin.
 * (Setup → Servers → Add New Server → choose module = ZenMove CRM)
 *
 * configoption1 = API URL
 * configoption2 = API Key
 * configoption3 = API Secret
 * configoption4 = CRM Plan (set per server, one server per plan tier)
 *
 * TIP: Create three servers in WHMCS (one per plan) and assign products
 * to the matching server group. Alternatively, set crm_plan as a product
 * custom field to override configoption4 on a per-product basis.
 */
function zenmovecrm_ConfigOptions(): array
{
    return [
        1 => [
            'FriendlyName' => 'API URL',
            'Type'         => 'text',
            'Size'         => 50,
            'Default'      => 'https://zenmove.ca',
            'Description'  => 'ZenMove base URL — no trailing slash. Do not change unless instructed.',
        ],
        2 => [
            'FriendlyName' => 'API Key',
            'Type'         => 'text',
            'Size'         => 60,
            'Default'      => '',
            'Description'  => 'Your ZenMove reseller API key (starts with zm_). Found in your ZenMove dashboard under Reseller Access.',
        ],
        3 => [
            'FriendlyName' => 'API Secret',
            'Type'         => 'password',
            'Size'         => 60,
            'Default'      => '',
            'Description'  => 'Your ZenMove reseller API secret. Shown once at application time.',
        ],
        4 => [
            'FriendlyName' => 'CRM Plan',
            'Type'         => 'dropdown',
            'Options'      => 'crm_starter,crm_growth,crm_pro',
            'Default'      => 'crm_starter',
            'Description'  => 'The CRM tier this server provisions. Create one server per plan tier. Can be overridden per-product via a custom field named <strong>crm_plan</strong>.',
        ],
    ];
}

/* ══════════════════════════════════════════════════════════════════════
 * PROVISIONING HOOKS
 * ══════════════════════════════════════════════════════════════════════ */

/**
 * CreateAccount — called when a new order is accepted and paid.
 *
 * Sends a provision request to ZenMove. The job is queued asynchronously;
 * ZenMove admin provisions the instance and the client receives an email.
 *
 * WHMCS marks the service Active immediately upon 'success' return.
 */
function zenmovecrm_CreateAccount(array $params): string
{
    try {
        $client   = _zenmovecrm_client($params);
        $plan     = _zenmovecrm_plan($params);
        $subdomain = _zenmovecrm_subdomain((string)($params['domain'] ?? ''));
        $company  = _zenmovecrm_company($params);

        if ($subdomain === '') {
            return 'Could not determine subdomain from the order domain field. '
                 . 'The client must enter their desired subdomain (e.g. "maven") at checkout.';
        }

        $requestData = [
            'company_name'     => $company,
            'subdomain'        => $subdomain,
            'plan'             => $plan,
            'whmcs_service_id' => (string)$params['serviceid'],
            'mode'             => 'async',
            'meta'             => [
                'whmcs_client_id' => (int)($params['clientdetails']['id'] ?? 0),
                'whmcs_pid'       => (int)($params['pid'] ?? 0),
                'source'          => 'whmcs_module_v' . ZENMOVE_MODULE_VERSION,
            ],
        ];

        $result = $client->provision($requestData);

        logModuleCall(
            'zenmovecrm',
            'CreateAccount',
            _zenmovecrm_redact($requestData),
            $result,
            $result['ok'] ? 'Provisioning job queued (job_id: ' . ($result['job_id'] ?? '?') . ')' : ($result['message'] ?? ''),
            [$params['configoption2'] ?? '', $params['configoption3'] ?? '']
        );

        if (!($result['ok'] ?? false)) {
            $error = $result['message'] ?? ($result['error'] ?? 'Unknown error from ZenMove API.');
            return 'ZenMove API error: ' . $error;
        }

        return 'success';

    } catch (Throwable $e) {
        logModuleCall('zenmovecrm', 'CreateAccount', [], [], $e->getMessage(), []);
        return 'Exception: ' . $e->getMessage();
    }
}

/**
 * SuspendAccount — called when a service is suspended (non-payment, manual, etc.)
 */
function zenmovecrm_SuspendAccount(array $params): string
{
    try {
        $client = _zenmovecrm_client($params);

        $requestData = [
            'whmcs_service_id' => (string)$params['serviceid'],
        ];

        $result = $client->suspend($requestData);

        logModuleCall(
            'zenmovecrm',
            'SuspendAccount',
            $requestData,
            $result,
            $result['ok'] ? 'Suspended' : ($result['message'] ?? ''),
            [$params['configoption2'] ?? '', $params['configoption3'] ?? '']
        );

        if (!($result['ok'] ?? false)) {
            // 409 "already_suspended" is not an error from WHMCS's perspective
            if (($result['error'] ?? '') === 'already_suspended') {
                return 'success';
            }
            return 'ZenMove API error: ' . ($result['message'] ?? $result['error'] ?? 'Unknown error.');
        }

        return 'success';

    } catch (Throwable $e) {
        logModuleCall('zenmovecrm', 'SuspendAccount', [], [], $e->getMessage(), []);
        return 'Exception: ' . $e->getMessage();
    }
}

/**
 * UnsuspendAccount — called when a suspended service is reactivated.
 */
function zenmovecrm_UnsuspendAccount(array $params): string
{
    try {
        $client = _zenmovecrm_client($params);

        $requestData = [
            'whmcs_service_id' => (string)$params['serviceid'],
        ];

        $result = $client->unsuspend($requestData);

        logModuleCall(
            'zenmovecrm',
            'UnsuspendAccount',
            $requestData,
            $result,
            $result['ok'] ? 'Reactivated' : ($result['message'] ?? ''),
            [$params['configoption2'] ?? '', $params['configoption3'] ?? '']
        );

        if (!($result['ok'] ?? false)) {
            if (($result['error'] ?? '') === 'already_active') {
                return 'success';
            }
            return 'ZenMove API error: ' . ($result['message'] ?? $result['error'] ?? 'Unknown error.');
        }

        return 'success';

    } catch (Throwable $e) {
        logModuleCall('zenmovecrm', 'UnsuspendAccount', [], [], $e->getMessage(), []);
        return 'Exception: ' . $e->getMessage();
    }
}

/**
 * TerminateAccount — called when a service is cancelled/terminated.
 * This is irreversible on the ZenMove side.
 */
function zenmovecrm_TerminateAccount(array $params): string
{
    try {
        $client = _zenmovecrm_client($params);

        $requestData = [
            'whmcs_service_id' => (string)$params['serviceid'],
            'reason'           => 'whmcs_termination',
        ];

        $result = $client->terminate($requestData);

        logModuleCall(
            'zenmovecrm',
            'TerminateAccount',
            $requestData,
            $result,
            $result['ok'] ? 'Terminated' : ($result['message'] ?? ''),
            [$params['configoption2'] ?? '', $params['configoption3'] ?? '']
        );

        if (!($result['ok'] ?? false)) {
            // 409 "already_terminated" — already gone, treat as success
            if (($result['error'] ?? '') === 'already_terminated') {
                return 'success';
            }
            // 404 — instance was never fully provisioned (e.g. cancelled before queued job ran)
            if (($result['http_status'] ?? 0) === 404) {
                return 'success';
            }
            return 'ZenMove API error: ' . ($result['message'] ?? $result['error'] ?? 'Unknown error.');
        }

        return 'success';

    } catch (Throwable $e) {
        logModuleCall('zenmovecrm', 'TerminateAccount', [], [], $e->getMessage(), []);
        return 'Exception: ' . $e->getMessage();
    }
}

/**
 * ChangePackage — called when a client upgrades or downgrades their product.
 * The new plan is resolved from the incoming product's config.
 */
function zenmovecrm_ChangePackage(array $params): string
{
    try {
        $client  = _zenmovecrm_client($params);
        $newPlan = _zenmovecrm_plan($params); // resolves from the NEW product's config

        $requestData = [
            'whmcs_service_id' => (string)$params['serviceid'],
            'plan'             => $newPlan,
        ];

        $result = $client->changePlan($requestData);

        logModuleCall(
            'zenmovecrm',
            'ChangePackage',
            $requestData,
            $result,
            $result['ok'] ? "Plan changed to {$newPlan}" : ($result['message'] ?? ''),
            [$params['configoption2'] ?? '', $params['configoption3'] ?? '']
        );

        if (!($result['ok'] ?? false)) {
            return 'ZenMove API error: ' . ($result['message'] ?? $result['error'] ?? 'Unknown error.');
        }

        return 'success';

    } catch (Throwable $e) {
        logModuleCall('zenmovecrm', 'ChangePackage', [], [], $e->getMessage(), []);
        return 'Exception: ' . $e->getMessage();
    }
}

/* ══════════════════════════════════════════════════════════════════════
 * CLIENT AREA
 * ══════════════════════════════════════════════════════════════════════ */

/**
 * ClientArea — output shown to the client on the service details page.
 * Fetches live instance status from ZenMove and renders a status card.
 */
function zenmovecrm_ClientArea(array $params): array
{
    $serviceId = (string)$params['serviceid'];

    try {
        $client = _zenmovecrm_client($params);
        $result = $client->getInstance(['whmcs_service_id' => $serviceId]);
    } catch (Throwable $e) {
        return [
            'templatefile' => 'clientarea',
            'vars'         => [
                'status_html' => _zenmovecrm_alert('danger', 'Could not connect to ZenMove API: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES)),
            ],
        ];
    }

    if (!($result['ok'] ?? false)) {
        $httpStatus = $result['http_status'] ?? 0;

        // Still provisioning — job not complete yet
        if ($httpStatus === 404) {
            $html = _zenmovecrm_provisioning_card();
        } else {
            $html = _zenmovecrm_alert('warning', 'Could not retrieve instance details: ' . htmlspecialchars($result['message'] ?? 'Unknown error.', ENT_QUOTES));
        }

        return ['templatefile' => 'clientarea', 'vars' => ['status_html' => $html]];
    }

    $instance = $result['instance'] ?? [];
    $html     = _zenmovecrm_instance_card($instance);

    return [
        'templatefile' => 'clientarea',
        'vars'         => ['status_html' => $html],
    ];
}

/* ══════════════════════════════════════════════════════════════════════
 * ADMIN SERVICES TAB
 * ══════════════════════════════════════════════════════════════════════ */

/**
 * AdminServicesTabFields — extra fields shown on the service in WHMCS admin.
 * Lets admins see the ZenMove instance status without leaving WHMCS.
 */
function zenmovecrm_AdminServicesTabFields(array $params): array
{
    $serviceId = (string)$params['serviceid'];
    $fields    = [];

    try {
        $client = _zenmovecrm_client($params);
        $result = $client->getInstance(['whmcs_service_id' => $serviceId]);

        if ($result['ok'] ?? false) {
            $i = $result['instance'] ?? [];

            $statusColors = [
                'active'     => '#198754',
                'suspended'  => '#fd7e14',
                'terminated' => '#dc3545',
            ];
            $instanceStatus = $i['instance_status'] ?? 'unknown';
            $color = $statusColors[$instanceStatus] ?? '#6c757d';

            $fields['ZenMove Job ID']        = (string)($i['job_id'] ?? '—');
            $fields['Instance Status']       = '<span style="color:' . $color . ';font-weight:600;">' . htmlspecialchars(strtoupper($instanceStatus), ENT_QUOTES) . '</span>';
            $fields['Job Status']            = htmlspecialchars((string)($i['job_status'] ?? '—'), ENT_QUOTES);
            $fields['CRM Plan']              = htmlspecialchars((string)($i['plan_label'] ?? $i['plan'] ?? '—'), ENT_QUOTES);
            $fields['Domain']                = htmlspecialchars((string)($i['domain'] ?? '—'), ENT_QUOTES);
            $fields['Provisioned']           = $i['provisioned_at']
                ? htmlspecialchars(date('M j, Y g:ia', strtotime($i['provisioned_at'])), ENT_QUOTES)
                : '—';

            if (!empty($i['error_message'])) {
                $fields['Provision Error'] = '<span style="color:#dc3545;">' . htmlspecialchars((string)$i['error_message'], ENT_QUOTES) . '</span>';
            }

            $adminUrl = rtrim(trim($params['configoption1'] ?? 'https://zenmove.ca'), '/')
                      . '/admin/provisioning.php?job_id=' . (int)($i['job_id'] ?? 0);
            $fields['Admin Link'] = '<a href="' . htmlspecialchars($adminUrl, ENT_QUOTES) . '" target="_blank">View in ZenMove Admin →</a>';

        } else {
            $fields['Status'] = 'Not yet provisioned or instance not found (service ID: ' . htmlspecialchars($serviceId, ENT_QUOTES) . ')';
        }
    } catch (Throwable $e) {
        $fields['Error'] = 'API connection failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES);
    }

    return $fields;
}

function zenmovecrm_AdminServicesTabFieldsSave(array $params): void
{
    // Read-only tab — nothing to save.
}

/* ══════════════════════════════════════════════════════════════════════
 * PRIVATE HELPERS
 * ══════════════════════════════════════════════════════════════════════ */

/**
 * Build a ZenMoveClient from WHMCS $params (server configoptions).
 */
function _zenmovecrm_client(array $params): ZenMoveClient
{
    $url    = rtrim(trim($params['configoption1'] ?? 'https://zenmove.ca'), '/');
    $key    = trim($params['configoption2'] ?? '');
    $secret = trim($params['configoption3'] ?? '');

    if ($key === '') {
        throw new RuntimeException('ZenMove API Key is not configured. Go to Setup → Servers and add your API key.');
    }

    return new ZenMoveClient($url, $key, $secret);
}

/**
 * Resolve the CRM plan.
 * Priority: product custom field 'crm_plan' > server configoption4
 */
function _zenmovecrm_plan(array $params): string
{
    $valid = array_keys(ZENMOVE_VALID_PLANS);

    // 1. Product-level custom field (allows one server to serve multiple plans)
    $cf = trim((string)($params['customfields']['crm_plan'] ?? ''));
    if ($cf !== '' && in_array($cf, $valid, true)) {
        return $cf;
    }

    // 2. Server configoption4 dropdown
    $opt = trim((string)($params['configoption4'] ?? ''));
    if (in_array($opt, $valid, true)) {
        return $opt;
    }

    // Safe default — will be validated by ZenMove API anyway
    return 'crm_starter';
}

/**
 * Extract and normalise a subdomain from the WHMCS domain field.
 *
 * The client should enter just the subdomain (e.g. "maven") at checkout.
 * This function also handles full domains like "maven.zenmove.ca" gracefully.
 */
function _zenmovecrm_subdomain(string $domain): string
{
    $d = strtolower(trim($domain));

    // Strip protocol if somehow present
    $d = preg_replace('~^https?://~i', '', $d);

    // Strip .zenmove.ca suffix
    $d = preg_replace('~\.zenmove\.ca$~i', '', $d);

    // If still a dotted domain (e.g. they typed their own TLD), take first label
    if (strpos($d, '.') !== false) {
        $d = explode('.', $d)[0];
    }

    // Normalise: only a-z, 0-9, hyphen
    $d = preg_replace('~[^a-z0-9-]~', '', $d);
    $d = preg_replace('~-+~', '-', $d);
    $d = trim($d, '-');

    return $d;
}

/**
 * Get company name from client details.
 */
function _zenmovecrm_company(array $params): string
{
    $company = trim((string)($params['clientdetails']['companyname'] ?? ''));
    if ($company !== '') {
        return $company;
    }

    $first = trim((string)($params['clientdetails']['firstname'] ?? ''));
    $last  = trim((string)($params['clientdetails']['lastname'] ?? ''));
    $full  = trim("{$first} {$last}");

    return $full !== '' ? $full : 'Unknown';
}

/**
 * Redact sensitive fields from request data before logging.
 */
function _zenmovecrm_redact(array $data): array
{
    $sensitive = ['api_key', 'api_secret', 'password', 'secret'];
    foreach ($sensitive as $key) {
        if (isset($data[$key])) {
            $data[$key] = '***REDACTED***';
        }
    }
    return $data;
}

/**
 * Render a Bootstrap-compatible alert box.
 */
function _zenmovecrm_alert(string $type, string $message): string
{
    $type = htmlspecialchars($type, ENT_QUOTES);
    return '<div class="alert alert-' . $type . '">' . $message . '</div>';
}

/**
 * Render the "provisioning in progress" card shown before a job completes.
 */
function _zenmovecrm_provisioning_card(): string
{
    return '
    <div class="panel panel-default">
      <div class="panel-heading"><h3 class="panel-title">ZenMove CRM — Provisioning</h3></div>
      <div class="panel-body text-center" style="padding:30px;">
        <div style="font-size:2.5rem; color:#0d6efd; margin-bottom:12px;">⏳</div>
        <h4>Your CRM is being set up</h4>
        <p class="text-muted">
          Our team is provisioning your ZenMove CRM instance.<br>
          You will receive a confirmation email once it\'s ready — usually within one business day.
        </p>
      </div>
    </div>';
}

/**
 * Render the main instance status card for the client area.
 */
function _zenmovecrm_instance_card(array $instance): string
{
    $status     = $instance['instance_status'] ?? 'active';
    $jobStatus  = $instance['job_status']      ?? '';
    $domain     = $instance['domain']          ?? '';
    $planLabel  = $instance['plan_label']      ?? ($instance['plan'] ?? '—');
    $provDate   = $instance['provisioned_at']  ?? null;

    $statusConfig = [
        'active'     => ['label' => 'Active',     'class' => 'success', 'icon' => '✅'],
        'suspended'  => ['label' => 'Suspended',  'class' => 'warning', 'icon' => '⏸'],
        'terminated' => ['label' => 'Terminated', 'class' => 'danger',  'icon' => '❌'],
    ];
    $sc    = $statusConfig[$status] ?? ['label' => ucfirst($status), 'class' => 'default', 'icon' => '●'];
    $color = ['success' => '#198754', 'warning' => '#fd7e14', 'danger' => '#dc3545', 'default' => '#6c757d'][$sc['class']];

    // If job not yet complete, show provisioning state
    if ($jobStatus !== 'complete' && $status !== 'terminated') {
        return _zenmovecrm_provisioning_card();
    }

    $domainHtml = '';
    if ($domain !== '' && $status === 'active') {
        $safeUrl  = htmlspecialchars('https://' . $domain, ENT_QUOTES);
        $safeDom  = htmlspecialchars($domain, ENT_QUOTES);
        $domainHtml = '<a href="' . $safeUrl . '" target="_blank" rel="noopener">' . $safeDom . ' →</a>';
    } elseif ($domain !== '') {
        $domainHtml = htmlspecialchars($domain, ENT_QUOTES);
    }

    $provDateHtml = $provDate
        ? htmlspecialchars(date('F j, Y', strtotime($provDate)), ENT_QUOTES)
        : '—';

    $suspendedMsg = '';
    if ($status === 'suspended') {
        $suspendedMsg = '<div class="alert alert-warning" style="margin-top:15px;">
            Your CRM is currently suspended. Please contact support or settle any outstanding invoices to reactivate.
        </div>';
    }

    $terminatedMsg = '';
    if ($status === 'terminated') {
        $terminatedMsg = '<div class="alert alert-danger" style="margin-top:15px;">
            This CRM instance has been terminated and is no longer accessible.
        </div>';
    }

    return '
    <div class="panel panel-default">
      <div class="panel-heading" style="background:#f8f9fa;">
        <h3 class="panel-title" style="display:flex;align-items:center;gap:8px;">
          ZenMove CRM
          <span style="margin-left:auto;font-size:0.75rem;font-weight:600;padding:3px 10px;border-radius:12px;background:' . $color . ';color:#fff;">'
            . $sc['icon'] . '&nbsp;' . htmlspecialchars($sc['label'], ENT_QUOTES) .
          '</span>
        </h3>
      </div>
      <div class="panel-body">
        <table class="table table-striped" style="margin-bottom:0;">
          <tbody>
            <tr>
              <th style="width:35%;">Plan</th>
              <td>' . htmlspecialchars($planLabel, ENT_QUOTES) . '</td>
            </tr>
            <tr>
              <th>CRM URL</th>
              <td>' . ($domainHtml ?: '—') . '</td>
            </tr>
            <tr>
              <th>Provisioned</th>
              <td>' . $provDateHtml . '</td>
            </tr>
          </tbody>
        </table>
        ' . $suspendedMsg . $terminatedMsg . '
      </div>
    </div>';
}
