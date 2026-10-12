import { Alert, Platform } from 'react-native';

/** A yes/no question before something is sent or decided. */
export function confirmAction(message: string, yes: string, no: string): Promise<boolean> {
  if (Platform.OS === 'web') return Promise.resolve(window.confirm(message));

  return new Promise((resolve) => Alert.alert(message, undefined, [{ text: no, style: 'cancel', onPress: () => resolve(false) }, { text: yes, onPress: () => resolve(true) }]));
}
