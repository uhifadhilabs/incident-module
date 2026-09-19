import { Controller } from '@hotwired/stimulus';

/*
 * DEPRECATED, AND A NO-OP. The filter bar's dropdowns are the shell's `.i-dd`
 * now — a `<details>` the browser opens on its own — so nothing here has
 * anything left to do: no panel to toggle, no outside click to watch, no
 * `.open` class that any stylesheet reads.
 *
 * IT IS STILL SHIPPED BECAUSE DELETING IT WOULD BREAK EVERY INSTALLATION THAT
 * HAS IT. Flex keeps a host's own `assets/controllers.json` entry when a
 * package it already has is updated, so a host that enabled this controller
 * keeps it enabled; with the file gone its Stimulus loader throws "Controller
 * … does not exist in the package" while a page renders, which is a 500 on
 * every screen after a `composer update` that changed nothing they asked for.
 *
 * SO IT GOES IN TWO STEPS. This release ships the no-op and turns the default
 * OFF, so no new installation enables it; **0.4.0 removes the file and the
 * `assets/package.json` entry**, by which time an upgrading host has had a
 * release in which to drop the line. Nothing should mount it in the meantime:
 * no template of this module's does.
 */
export default class extends Controller {
    connect() {
        // Deliberately nothing.
    }
}
