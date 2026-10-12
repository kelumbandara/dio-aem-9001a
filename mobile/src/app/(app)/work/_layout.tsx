import { useSession } from '@/auth/session';
import { StackLayout } from '@/ui/StackLayout';

export default function WorkLayout() {
  const { t } = useSession();

  return <StackLayout title={t('work_title')} />;
}
