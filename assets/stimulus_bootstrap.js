/*
 * Stimulus, which is what runs the Live Components (XIV-33).
 *
 * The app is put on `window` — the convention the Stimulus docs use for
 * debugging, and the only way to ask from outside whether any of this is
 * actually running. A browser test asks exactly that (XIV-31), because an
 * importmap that fails to resolve leaves a page that renders perfectly and does
 * nothing at all when a button is pressed.
 */
import { startStimulusApp } from '@symfony/stimulus-bundle';

const app = startStimulusApp();

window.Stimulus = app;
// register any custom, 3rd party controllers here
// app.register('some_controller_name', SomeImportedController);
