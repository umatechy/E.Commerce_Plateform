import AdminPage from '@/Components/AdminPage';
import SettingsEditor, { type SettingInfo } from '@/Components/SettingsEditor';

/**
 * Module 33 platform settings (/api/v1/super-admin/settings). Platform
 * staff only; every change asks for the password again (step-up) and is
 * recorded in the audit trail. The settings shown are the ones the
 * server's registry has for the platform; this page names them.
 */
const INFO: Record<string, SettingInfo> = {
  'platform.supported_currencies': { label: 'Supported currencies', description: 'The currencies stores may use.', itemHint: 'One three-letter code per line, such as USD.' },
  'platform.default_locale': { label: 'Default language', description: 'Used by a store that has not chosen its own.', itemHint: 'A language code such as en.' },
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
      <SettingsEditor listPath="/super-admin/settings" updatePath="/super-admin/settings" info={INFO} canManage />
    </AdminPage>
  );
}
