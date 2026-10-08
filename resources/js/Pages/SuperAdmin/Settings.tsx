import AdminPage from '@/Components/AdminPage';
import SettingsEditor, { type SettingInfo } from '@/Components/SettingsEditor';

/**
 * Module 33 platform settings (/api/v1/super-admin/settings). Platform
 * staff only; every change asks for the password again (step-up) and is
 * recorded in the audit trail. Each setting has its history, and an
 * earlier value can be put back (Phase B32). The settings shown are the ones the
 * server's registry has for the platform; this page names them.
 */
const INFO: Record<string, SettingInfo> = {
  'platform.supported_currencies': {
    label: 'Supported currencies',
    description: 'The currencies stores may choose from. New stores use PKR (Rs.).',
    itemHint: 'One three-letter code per line, such as PKR or USD.',
    warning: 'A store keeps its currency even if you remove it here; only new choices are limited.',
  },
  'platform.default_locale': { label: 'Default language', description: 'Used by a store that has not chosen its own.', itemHint: 'A language code such as en.' },
  // Phase B44 (owner decision 13, Module 04 §14).
  'platform.self_signup_enabled': {
    label: 'Customers can sign up and create a store themselves',
    description: 'When off, the sign-up page tells visitors to contact the Umar Techy team, and only the team creates stores (Stores → Create store for a customer).',
  },
  'platform.launch_requires_payment': {
    label: 'A store goes live only after its first payment',
    description: 'When on, an owner cannot launch during the trial until their first invoice is paid. They get the invoice from their setup steps; the team records the payment under Billing.',
  },
  'platform.trial_package': { label: 'Trial package', description: 'The package a new self-service store starts its trial on.', itemHint: 'A package code such as basic.' },
  // Phase B47 (Module 29): Umar Techy's own billing to stores.
  'billing.tax_rate_bps': { label: 'Tax on platform invoices (basis points)', description: '1700 = 17 %. 0 = no tax. Applies to invoices issued from now on; issued invoices keep their tax.' },
  'billing.tax_label': { label: 'Tax name on platform invoices', description: 'For example Tax, GST or Sales tax.' },
  'billing.approval_threshold_minor': { label: 'Credit notes need a second person from (minor units)', description: 'A credit note of this amount or more waits until another team member approves it. 0 = no approval step. In minor units: 500000 = Rs. 5,000.' },
  'billing.upgrade_timing': { label: 'When an upgrade starts', description: 'immediate_prorated: at once, with an invoice for the rest of the period. next_period: at the end of the paid period, like a downgrade.' },
  'billing.issuer_name': { label: 'Name on invoices', description: 'Who issues the invoices and credit notes (shown on the PDF).' },
  'billing.issuer_details': { label: 'Address and registration on invoices', description: 'Address, NTN / registration numbers, contact — printed under the name on every PDF.' },
  'platform.trial_days': { label: 'Trial length (days)', description: 'How long a new store’s trial lasts. Staff creating a store may choose another length.' },
  'platform.maintenance_mode': {
    label: 'Maintenance mode',
    description: 'Marks the platform as under maintenance.',
    warning: 'This affects every store on the platform. Switch it on only for planned work, and switch it off when the work is done.',
  },
  'api.default_rate_limit_per_minute': { label: 'Developer API rate limit', description: 'How many Developer API requests one key may make per minute.' },
  'backup.retention_days': { label: 'Daily backup retention (days)', description: 'How long daily and manual backups are kept.', warning: 'A shorter period lets older backups be deleted at the next cleanup. Deleted backups cannot be recovered.' },
  'backup.monthly_retention_months': { label: 'Monthly backup retention (months)', description: 'How long the monthly backups are kept.', warning: 'A shorter period lets older monthly backups be deleted at the next cleanup.' },
  'backup.automated_backups_enabled': { label: 'Scheduled backups', description: 'Take the daily platform backup automatically.', warning: 'With this off, no backup is taken unless someone starts one by hand. The platform will raise an alert when the last backup is too old.' },
  'backup.rehearsal_enabled': { label: 'Weekly restore rehearsal', description: 'Restore the latest backup into a throw-away database every week to prove it works.' },
  'alerts.critical_email_recipients': { label: 'Critical alert emails', description: 'Who is emailed when a backup, restore or cleanup fails. While empty, every active platform staff account is.', itemHint: 'One email address per line.' },
  'alerts.whatsapp_enabled': { label: 'Critical alerts by WhatsApp', description: 'Also send critical alerts by WhatsApp.', warning: 'No WhatsApp provider is connected yet. Until one is, these messages are recorded as failed and nothing is sent.' },
  'alerts.whatsapp_recipients': { label: 'WhatsApp alert numbers', description: 'Numbers for critical alerts by WhatsApp.', itemHint: 'One number per line, in international format such as +923001234567.' },
};

export default function Settings() {
  return (
    <AdminPage title="Platform settings" description="Configuration for the whole platform. You will be asked for your password when you change one.">
      <SettingsEditor
        listPath="/super-admin/settings"
        updatePath="/super-admin/settings"
        info={INFO}
        canManage
        historyPath={(key) => `/super-admin/settings/${key}/history`}
        rollbackPath={(id) => `/super-admin/settings/revisions/${id}/rollback`}
      />
    </AdminPage>
  );
}
