import { useSession } from '@/auth/session';
import { StackLayout } from '@/ui/StackLayout';

export default function AssistantLayout() {
  const { t } = useSession();

  return <StackLayout title={t('assistant_title')} />;
}
