import { useSession } from '@/auth/session';
import { StackLayout } from '@/ui/StackLayout';

export default function SalesLayout() {
  const { t } = useSession();

  return <StackLayout title={t('sales_title')} />;
}
