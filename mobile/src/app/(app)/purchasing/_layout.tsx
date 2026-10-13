import { useSession } from '@/auth/session';
import { StackLayout } from '@/ui/StackLayout';

export default function PurchasingLayout() {
  const { t } = useSession();

  return <StackLayout title={t('purchasing_title')} />;
}
