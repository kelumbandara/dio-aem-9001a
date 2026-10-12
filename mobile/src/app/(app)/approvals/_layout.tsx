import { useSession } from '@/auth/session';
import { StackLayout } from '@/ui/StackLayout';

export default function ApprovalsLayout() {
  const { t } = useSession();

  return <StackLayout title={t('approvals_title')} />;
}
