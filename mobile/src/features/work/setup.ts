import type { SetupRow } from '@/api/types';
import { useSession } from '@/auth/session';
import { useLoad } from '@/util/hooks';

export type CurrentShift = { shift: string; name: string; date: string } | null;

/** The shifts, machines and stoppage reasons the forms offer, and the shift running right now. */
export function useWorkSetup() {
  const { client } = useSession();

  return useLoad(async () => {
    const [shifts, machines, reasons, current] = await Promise.all([
      client.get<{ data: SetupRow[] }>('/manufacturing/shifts'),
      client.get<{ data: SetupRow[] }>('/manufacturing/machines'),
      client.get<{ data: SetupRow[] }>('/manufacturing/downtime-reasons'),
      client.get<{ data: CurrentShift }>('/shifts/current'),
    ]);

    return {
      shifts: shifts.data.filter((r) => r.is_active),
      machines: machines.data.filter((r) => r.is_active),
      reasons: reasons.data.filter((r) => r.is_active),
      current: current.data,
    };
  });
}

export type WorkSetup = NonNullable<ReturnType<typeof useWorkSetup>['data']>;

/** The shift date to put on an entry: the running shift's own date (a night shift after midnight belongs to the evening before), else today. */
export function entryDate(setup: WorkSetup, shift: string, todayDate: string): string {
  return setup.current && setup.current.shift === shift ? setup.current.date : todayDate;
}
