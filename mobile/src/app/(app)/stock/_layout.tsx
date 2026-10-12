import { useSession } from '@/auth/session';
import { StackLayout } from '@/ui/StackLayout';

export default function StockLayout() {
  const { t } = useSession();

  return <StackLayout title={t('stock_title')} />;
}
