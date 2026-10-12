import { useState, type PropsWithChildren, type ReactNode } from 'react';
import { ActivityIndicator, KeyboardAvoidingView, Platform, Pressable, RefreshControl, ScrollView, StyleSheet, Text, TextInput, useColorScheme, View, type TextInputProps } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

export function useTheme() {
  const dark = useColorScheme() === 'dark';

  return dark
    ? { dark, bg: '#0f172a', card: '#1e293b', text: '#f1f5f9', muted: '#94a3b8', border: '#334155', primary: '#818cf8', onPrimary: '#0f172a', danger: '#f87171', ok: '#4ade80', warn: '#fbbf24', input: '#0f172a' }
    : { dark, bg: '#f8fafc', card: '#ffffff', text: '#0f172a', muted: '#64748b', border: '#e2e8f0', primary: '#4f46e5', onPrimary: '#ffffff', danger: '#b91c1c', ok: '#15803d', warn: '#b45309', input: '#ffffff' };
}

/** A scrolling page with pull-to-refresh and room for the keyboard. */
export function Screen({ children, onRefresh, refreshing = false, padded = true }: PropsWithChildren<{ onRefresh?: () => void; refreshing?: boolean; padded?: boolean }>) {
  const th = useTheme();
  const insets = useSafeAreaInsets();

  return (
    <KeyboardAvoidingView style={{ flex: 1, backgroundColor: th.bg }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
      <ScrollView
        keyboardShouldPersistTaps="handled"
        contentContainerStyle={{ padding: padded ? 16 : 0, paddingBottom: insets.bottom + 24, gap: 12 }}
        refreshControl={onRefresh ? <RefreshControl refreshing={refreshing} onRefresh={onRefresh} tintColor={th.primary} /> : undefined}>
        {children}
      </ScrollView>
    </KeyboardAvoidingView>
  );
}

export function Card({ children, onPress, accessibilityLabel }: PropsWithChildren<{ onPress?: () => void; accessibilityLabel?: string }>) {
  const th = useTheme();
  const style = [styles.card, { backgroundColor: th.card, borderColor: th.border }];
  if (!onPress) return <View style={style}>{children}</View>;

  return (
    <Pressable accessibilityRole="button" accessibilityLabel={accessibilityLabel} onPress={onPress} style={({ pressed }) => [...style, pressed && { opacity: 0.7 }]}>
      {children}
    </Pressable>
  );
}

export function Title({ children }: PropsWithChildren) {
  const th = useTheme();

  return <Text accessibilityRole="header" style={[styles.title, { color: th.text }]}>{children}</Text>;
}

export function Body({ children, muted, bold, danger }: PropsWithChildren<{ muted?: boolean; bold?: boolean; danger?: boolean }>) {
  const th = useTheme();

  return <Text style={[styles.body, { color: danger ? th.danger : muted ? th.muted : th.text }, bold && { fontWeight: '600' }]}>{children}</Text>;
}

export function Button({ label, onPress, kind = 'primary', busy = false, disabled = false }: { label: string; onPress: () => void; kind?: 'primary' | 'secondary' | 'danger'; busy?: boolean; disabled?: boolean }) {
  const th = useTheme();
  const bg = kind === 'primary' ? th.primary : kind === 'danger' ? th.danger : 'transparent';
  const fg = kind === 'secondary' ? th.primary : th.onPrimary;
  const off = busy || disabled;

  return (
    <Pressable
      accessibilityRole="button"
      accessibilityState={{ disabled: off, busy }}
      disabled={off}
      onPress={onPress}
      style={({ pressed }) => [styles.button, { backgroundColor: bg, borderColor: kind === 'secondary' ? th.primary : bg, opacity: off ? 0.5 : pressed ? 0.8 : 1 }]}>
      {busy ? <ActivityIndicator color={fg} /> : <Text style={[styles.buttonText, { color: fg }]}>{label}</Text>}
    </Pressable>
  );
}

export function Field({ label, error, hint, ...input }: { label: string; error?: string; hint?: string } & TextInputProps) {
  const th = useTheme();
  const [focus, setFocus] = useState(false);

  return (
    <View style={{ gap: 4 }}>
      <Text style={[styles.label, { color: th.muted }]}>{label}</Text>
      <TextInput
        accessibilityLabel={label}
        placeholderTextColor={th.muted}
        onFocus={() => setFocus(true)}
        onBlur={() => setFocus(false)}
        {...input}
        style={[styles.input, { backgroundColor: th.input, color: th.text, borderColor: error ? th.danger : focus ? th.primary : th.border }, input.multiline && { minHeight: 88, textAlignVertical: 'top' }]}
      />
      {error ? <Text style={{ color: th.danger, fontSize: 13 }} accessibilityRole="alert">{error}</Text> : hint ? <Text style={{ color: th.muted, fontSize: 13 }}>{hint}</Text> : null}
    </View>
  );
}

/** A row of choices of which one is picked (shift, machine, reason …). */
export function Choice<T extends string>({ label, options, value, onChange, error }: { label: string; options: { value: T; label: string }[]; value: T | ''; onChange: (v: T) => void; error?: string }) {
  const th = useTheme();

  return (
    <View style={{ gap: 6 }}>
      <Text style={[styles.label, { color: th.muted }]}>{label}</Text>
      <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 8 }}>
        {options.map((o) => {
          const on = o.value === value;

          return (
            <Pressable
              key={o.value}
              accessibilityRole="radio"
              accessibilityState={{ selected: on }}
              onPress={() => onChange(o.value)}
              style={[styles.chip, { borderColor: on ? th.primary : th.border, backgroundColor: on ? th.primary : th.card }]}>
              <Text style={{ color: on ? th.onPrimary : th.text, fontSize: 15 }}>{o.label}</Text>
            </Pressable>
          );
        })}
      </View>
      {error ? <Text style={{ color: th.danger, fontSize: 13 }} accessibilityRole="alert">{error}</Text> : null}
    </View>
  );
}

export function Badge({ label, tone = 'muted' }: { label: string; tone?: 'ok' | 'warn' | 'danger' | 'muted' }) {
  const th = useTheme();
  const color = tone === 'ok' ? th.ok : tone === 'warn' ? th.warn : tone === 'danger' ? th.danger : th.muted;

  return (
    <View style={{ borderColor: color, borderWidth: 1, borderRadius: 999, paddingHorizontal: 8, paddingVertical: 2, alignSelf: 'flex-start' }}>
      <Text style={{ color, fontSize: 12, fontWeight: '600' }}>{label}</Text>
    </View>
  );
}

export function Row({ left, right }: { left: ReactNode; right?: ReactNode }) {
  return (
    <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'flex-start', gap: 12 }}>
      <View style={{ flex: 1 }}>{left}</View>
      {right ? <View style={{ alignItems: 'flex-end' }}>{right}</View> : null}
    </View>
  );
}

export function Loading() {
  const th = useTheme();

  return <ActivityIndicator style={{ marginTop: 32 }} color={th.primary} size="large" accessibilityLabel="Loading" />;
}

export function Notice({ text, tone = 'warn' }: { text: string; tone?: 'ok' | 'warn' | 'danger' }) {
  const th = useTheme();
  const color = tone === 'ok' ? th.ok : tone === 'danger' ? th.danger : th.warn;

  return (
    <View accessibilityRole="alert" style={{ borderColor: color, borderWidth: 1, borderRadius: 10, padding: 12, backgroundColor: th.card }}>
      <Text style={{ color, fontSize: 15 }}>{text}</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  card: { borderWidth: 1, borderRadius: 14, padding: 14, gap: 6 },
  title: { fontSize: 22, fontWeight: '700' },
  body: { fontSize: 16, lineHeight: 22 },
  button: { minHeight: 52, borderRadius: 12, borderWidth: 1, alignItems: 'center', justifyContent: 'center', paddingHorizontal: 16 },
  buttonText: { fontSize: 17, fontWeight: '600' },
  label: { fontSize: 13, fontWeight: '600' },
  input: { minHeight: 52, borderWidth: 1, borderRadius: 12, paddingHorizontal: 12, fontSize: 18 },
  chip: { minHeight: 44, borderWidth: 1, borderRadius: 999, paddingHorizontal: 16, alignItems: 'center', justifyContent: 'center' },
});
