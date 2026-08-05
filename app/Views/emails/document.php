<?php
/**
 * Transactional email body.
 *
 * Table-based with inline styles — the only thing Outlook, Gmail and
 * Apple Mail all render the same way. Solid brand colours, no gradients.
 *
 * @var string $event
 * @var string $intro
 * @var array  $context
 * @var array  $company
 * @var string $footer
 */

$navy  = '#0C2B4A';
$green = '#14874E';
$ink   = '#0F1E2E';
$muted = '#5A6B7D';
$line  = '#DDE4EC';
$bg    = '#F3F6F9';

$doc   = $context['document'] ?? null;
$job   = $context['job'] ?? null;
$link  = $context['link'] ?? '';

$isOverdue = $event === 'invoice_overdue';
$isPaid    = $event === 'payment_received';

$accent = $isOverdue ? '#A62A20' : ($isPaid ? $green : $navy);

$items = [];
if ($doc && !empty($doc['id'])) {
    $items = \App\Core\Database::all(
        'SELECT description, quantity, unit, unit_price, line_total
           FROM document_items WHERE document_id = :id ORDER BY sort_order, id',
        ['id' => $doc['id']]
    );
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($context['doc_number'] ?? $company['name']) ?></title>
</head>
<body style="margin:0;padding:0;background:<?= $bg ?>;font-family:Arial,Helvetica,sans-serif;color:<?= $ink ?>">

<!-- Preview text shown in the inbox list -->
<div style="display:none;max-height:0;overflow:hidden;opacity:0">
  <?= e(str_excerpt($intro, 120)) ?>
</div>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:<?= $bg ?>;padding:24px 12px">
<tr><td align="center">

  <table role="presentation" width="600" cellpadding="0" cellspacing="0"
         style="width:600px;max-width:100%;background:#ffffff;border:1px solid <?= $line ?>;border-radius:8px;overflow:hidden">

    <?php if (!empty($company['logo_url'])): ?>
    <!-- Letterhead. On white, because an uploaded logo may be any colour.
         If the client blocks remote images the alt text stands in. -->
    <tr>
      <td align="left" style="background:#ffffff;padding:18px 28px 14px">
        <img src="<?= e($company['logo_url']) ?>" alt="<?= e($company['name']) ?>"
             height="44" style="height:44px;width:auto;max-width:220px;border:0;display:block">
      </td>
    </tr>
    <?php endif; ?>

    <!-- Header -->
    <tr>
      <td style="background:<?= $navy ?>;padding:22px 28px">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
        <tr>
          <td style="color:#ffffff;font-size:19px;font-weight:bold;letter-spacing:-0.3px">
            <?= e($company['name']) ?>
          </td>
          <td align="right" style="color:#8FB3D9;font-size:12px">
            <?= e($context['doc_number'] ?? ($context['job_number'] ?? '')) ?>
          </td>
        </tr>
        <?php if (!empty($company['tagline'])): ?>
        <tr>
          <td colspan="2" style="color:#7FCDA3;font-size:12px;padding-top:3px">
            <?= e($company['tagline']) ?>
          </td>
        </tr>
        <?php endif; ?>
        </table>
      </td>
    </tr>

    <!-- Status strip -->
    <tr>
      <td style="background:<?= $accent ?>;height:4px;line-height:4px;font-size:0">&nbsp;</td>
    </tr>

    <!-- Body -->
    <tr>
      <td style="padding:28px">

        <p style="margin:0 0 6px;font-size:16px;font-weight:bold;color:<?= $ink ?>">
          Hello <?= e($context['contact_name'] ?? 'there') ?>,
        </p>

        <p style="margin:0 0 20px;font-size:14px;line-height:1.6;color:<?= $muted ?>">
          <?= nl2br(e($intro)) ?>
        </p>

        <?php if ($isPaid): ?>
          <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
                 style="background:#EDF8F2;border:1px solid #D5EFE0;border-radius:6px;margin-bottom:20px">
          <tr>
            <td style="padding:14px 16px;text-align:center">
              <div style="font-size:12px;color:#0B5730;text-transform:uppercase;letter-spacing:1px">Amount received</div>
              <div style="font-size:26px;font-weight:bold;color:<?= $green ?>;padding-top:4px">
                <?= e($context['amount'] ?? '') ?>
              </div>
            </td>
          </tr>
          </table>
        <?php endif; ?>

        <?php if ($doc): ?>
          <!-- Document summary -->
          <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
                 style="border:1px solid <?= $line ?>;border-radius:6px;margin-bottom:20px">
          <tr>
            <td style="padding:14px 16px">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:13px">
                <tr>
                  <td style="color:<?= $muted ?>;padding:3px 0"><?= e($context['doc_type']) ?> number</td>
                  <td align="right" style="font-weight:bold;padding:3px 0"><?= e($context['doc_number']) ?></td>
                </tr>
                <tr>
                  <td style="color:<?= $muted ?>;padding:3px 0">Date</td>
                  <td align="right" style="padding:3px 0"><?= e($context['issue_date']) ?></td>
                </tr>
                <?php if (!empty($doc['due_date']) && $doc['doc_type'] === 'invoice'): ?>
                <tr>
                  <td style="color:<?= $muted ?>;padding:3px 0">Payment due</td>
                  <td align="right" style="padding:3px 0;<?= $isOverdue ? 'color:#A62A20;font-weight:bold' : '' ?>">
                    <?= e($context['due_date']) ?>
                  </td>
                </tr>
                <?php endif; ?>
                <?php if (!empty($doc['valid_until']) && $doc['doc_type'] === 'quotation'): ?>
                <tr>
                  <td style="color:<?= $muted ?>;padding:3px 0">Valid until</td>
                  <td align="right" style="padding:3px 0"><?= e($context['valid_until']) ?></td>
                </tr>
                <?php endif; ?>
                <?php if (!empty($doc['title'])): ?>
                <tr>
                  <td style="color:<?= $muted ?>;padding:3px 0">Reference</td>
                  <td align="right" style="padding:3px 0"><?= e($doc['title']) ?></td>
                </tr>
                <?php endif; ?>
              </table>
            </td>
          </tr>
          </table>

          <?php if ($items): ?>
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
                   style="border-collapse:collapse;margin-bottom:4px;font-size:13px">
            <tr>
              <th align="left"  style="background:<?= $navy ?>;color:#fff;padding:9px 12px;font-size:11px;text-transform:uppercase;letter-spacing:0.5px">Description</th>
              <th align="right" style="background:<?= $navy ?>;color:#fff;padding:9px 8px;font-size:11px;text-transform:uppercase;width:52px">Qty</th>
              <th align="right" style="background:<?= $navy ?>;color:#fff;padding:9px 12px;font-size:11px;text-transform:uppercase;width:100px">Amount</th>
            </tr>
            <?php foreach ($items as $i => $item): ?>
              <tr style="background:<?= $i % 2 ? '#F8FAFC' : '#ffffff' ?>">
                <td style="padding:9px 12px;border-bottom:1px solid <?= $line ?>">
                  <?= nl2br(e($item['description'])) ?>
                </td>
                <td align="right" style="padding:9px 8px;border-bottom:1px solid <?= $line ?>;white-space:nowrap">
                  <?= e(qty($item['quantity'])) ?>
                </td>
                <td align="right" style="padding:9px 12px;border-bottom:1px solid <?= $line ?>;white-space:nowrap">
                  <?= e(money($item['line_total'], false)) ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </table>

            <!-- Totals -->
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:13px;margin-bottom:22px">
              <tr>
                <td align="right" style="padding:6px 12px;color:<?= $muted ?>">Subtotal</td>
                <td align="right" style="padding:6px 12px;width:110px"><?= e(money($doc['subtotal'])) ?></td>
              </tr>
              <?php if ((float) ($doc['discount_amount'] ?? 0) > 0): ?>
              <tr>
                <td align="right" style="padding:6px 12px;color:<?= $muted ?>">Discount</td>
                <td align="right" style="padding:6px 12px">&minus; <?= e(money($doc['discount_amount'])) ?></td>
              </tr>
              <?php endif; ?>
              <?php if (($doc['vat_mode'] ?? '') !== 'exempt'): ?>
              <tr>
                <td align="right" style="padding:6px 12px;color:<?= $muted ?>">
                  VAT (<?= e(qty($doc['vat_rate'])) ?>%)
                </td>
                <td align="right" style="padding:6px 12px"><?= e(money($doc['vat_amount'])) ?></td>
              </tr>
              <?php endif; ?>
              <tr>
                <td align="right" style="background:<?= $navy ?>;color:#fff;padding:11px 12px;font-weight:bold;font-size:15px">
                  Total
                </td>
                <td align="right" style="background:<?= $navy ?>;color:#fff;padding:11px 12px;font-weight:bold;font-size:15px;white-space:nowrap">
                  <?= e(money($doc['total'])) ?>
                </td>
              </tr>
              <?php if (($doc['doc_type'] ?? '') === 'invoice' && (float) ($doc['amount_paid'] ?? 0) > 0): ?>
              <tr>
                <td align="right" style="padding:6px 12px;color:<?= $green ?>">Already paid</td>
                <td align="right" style="padding:6px 12px;color:<?= $green ?>"><?= e(money($doc['amount_paid'])) ?></td>
              </tr>
              <tr>
                <td align="right" style="padding:6px 12px;color:#A62A20;font-weight:bold">Balance due</td>
                <td align="right" style="padding:6px 12px;color:#A62A20;font-weight:bold"><?= e(money($doc['balance'])) ?></td>
              </tr>
              <?php endif; ?>
            </table>
          <?php endif; ?>
        <?php endif; ?>

        <?php if ($job): ?>
          <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
                 style="border:1px solid <?= $line ?>;border-radius:6px;margin-bottom:20px">
          <tr>
            <td style="padding:14px 16px;font-size:13px">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                <tr>
                  <td style="color:<?= $muted ?>;padding:3px 0">Order number</td>
                  <td align="right" style="font-weight:bold;padding:3px 0"><?= e($context['job_number']) ?></td>
                </tr>
                <tr>
                  <td style="color:<?= $muted ?>;padding:3px 0">Description</td>
                  <td align="right" style="padding:3px 0"><?= e($context['job_title']) ?></td>
                </tr>
              </table>
            </td>
          </tr>
          </table>
        <?php endif; ?>

        <?php if ($link !== ''): ?>
          <!-- Bulletproof-ish button -->
          <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 auto 20px">
            <tr>
              <td align="center" style="background:<?= $green ?>;border-radius:6px">
                <a href="<?= e($link) ?>"
                   style="display:inline-block;padding:13px 30px;color:#ffffff;font-size:15px;font-weight:bold;text-decoration:none">
                  View <?= e(strtolower($context['doc_type'] ?? 'document')) ?> online
                </a>
              </td>
            </tr>
          </table>

          <p style="margin:0 0 20px;font-size:12px;color:<?= $muted ?>;text-align:center">
            Or paste this into your browser:<br>
            <span style="color:<?= $navy ?>;word-break:break-all"><?= e($link) ?></span>
          </p>
        <?php endif; ?>

        <?php if (($doc['doc_type'] ?? '') === 'invoice' && !$isPaid): ?>
          <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
                 style="background:#F8FAFC;border:1px solid <?= $line ?>;border-radius:6px">
          <tr>
            <td style="padding:14px 16px;font-size:13px;line-height:1.7">
              <strong style="color:<?= $ink ?>">How to pay</strong><br>
              <?php if (setting('mpesa_till')): ?>
                <span style="color:<?= $muted ?>">M-Pesa Buy Goods Till:</span>
                <strong><?= e(setting('mpesa_till')) ?></strong><br>
              <?php endif; ?>
              <?php if (setting('bank_details')): ?>
                <span style="color:<?= $muted ?>"><?= nl2br(e(setting('bank_details'))) ?></span><br>
              <?php endif; ?>
              <span style="color:<?= $muted ?>">
                Please quote <strong style="color:<?= $ink ?>"><?= e($context['doc_number']) ?></strong>
                as your payment reference.
              </span>
            </td>
          </tr>
          </table>
        <?php endif; ?>

      </td>
    </tr>

    <!-- Footer -->
    <tr>
      <td style="background:#F8FAFC;border-top:1px solid <?= $line ?>;padding:18px 28px;text-align:center">
        <p style="margin:0 0 6px;font-size:13px;font-weight:bold;color:<?= $ink ?>">
          <?= e($company['name']) ?>
        </p>
        <p style="margin:0 0 10px;font-size:12px;color:<?= $muted ?>;line-height:1.6">
          <?php if (!empty($company['address'])): ?><?= e($company['address']) ?><br><?php endif; ?>
          <?php if (!empty($company['phone'])): ?><?= e($company['phone']) ?><?php endif; ?>
          <?php if (!empty($company['email'])): ?> &middot; <?= e($company['email']) ?><?php endif; ?>
          <?php if (!empty($company['website'])): ?><br><?= e($company['website']) ?><?php endif; ?>
        </p>
        <?php if ($footer !== ''): ?>
          <p style="margin:0;font-size:11px;color:#9AA8B5"><?= e($footer) ?></p>
        <?php endif; ?>
      </td>
    </tr>

  </table>

</td></tr>
</table>
</body>
</html>
