// First, before anything that could fail: report what goes wrong.
import './src/guard';
// Then the push background task - Android may start the app ONLY to run it
// (a button pressed on a notification with the app shut), so it must exist
// before anything else is drawn. See src/push.js.
import './src/push';
import { registerRootComponent } from 'expo';

import App from './App';

// registerRootComponent calls AppRegistry.registerComponent('main', () => App);
// It also ensures that whether you load the app in Expo Go or in a native build,
// the environment is set up appropriately
registerRootComponent(App);
