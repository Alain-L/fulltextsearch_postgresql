/**
 * SPDX-FileCopyrightText: 2026 Alain Lesage
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Admin panel: one setting, no framework, no build step.
 *
 * Everything comes from the DOM — the URL, the token, the translated messages. No Nextcloud
 * global is assumed available: `OC.requestToken` is not injected into the settings page, and
 * `OC.linkToOCS` is not guaranteed.
 */
document.addEventListener('DOMContentLoaded', function () {
	const racine = document.getElementById('fulltextsearch_postgresql')
	const auto = document.getElementById('ftspg-auto')
	const manuel = document.getElementById('ftspg-manual')
	const liste = document.getElementById('ftspg-chips')
	const ajout = document.getElementById('ftspg-add')
	const bouton = document.getElementById('ftspg-save')
	const retour = document.getElementById('ftspg-feedback')
	if (!racine || !auto || !manuel || !liste || !ajout || !bouton) {
		return
	}

	/** The selected languages, in order: the first one is the primary. */
	function retenues() {
		return Array.from(liste.querySelectorAll('.ftspg-chip')).map(function (chip) {
			return chip.dataset.lang
		})
	}

	/** A language already selected must not be offered for adding any more. */
	function rafraichirChoix() {
		const prises = retenues()
		Array.from(ajout.options).forEach(function (option) {
			if (option.value !== '') {
				option.hidden = prises.indexOf(option.value) !== -1
			}
		})
		ajout.value = ''
	}

	function ajouterChip(langue) {
		const chip = document.createElement('li')
		chip.className = 'ftspg-chip'
		chip.dataset.lang = langue

		const nom = document.createElement('span')
		nom.textContent = langue

		const retirer = document.createElement('button')
		retirer.type = 'button'
		retirer.className = 'ftspg-chip-remove'
		retirer.setAttribute('aria-label', racine.dataset.msgRemove)
		retirer.innerHTML = '&times;'

		chip.appendChild(nom)
		chip.appendChild(retirer)
		liste.appendChild(chip)
	}

	function basculerMode() {
		manuel.setAttribute('aria-disabled', auto.checked ? 'true' : 'false')
	}

	// Removal is delegated: chips come and go as the clicks pile up.
	liste.addEventListener('click', function (evenement) {
		const retirer = evenement.target.closest('.ftspg-chip-remove')
		if (retirer) {
			retirer.closest('.ftspg-chip').remove()
			rafraichirChoix()
		}
	})

	ajout.addEventListener('change', function () {
		if (ajout.value !== '') {
			ajouterChip(ajout.value)
			rafraichirChoix()
		}
	})

	auto.addEventListener('change', basculerMode)

	bouton.addEventListener('click', function () {
		const choisies = retenues()
		if (!auto.checked && choisies.length === 0) {
			retour.textContent = racine.dataset.msgPick
			return
		}

		bouton.disabled = true
		retour.textContent = ''

		fetch(racine.dataset.saveUrl + '?format=json', {
			method: 'PUT',
			headers: {
				'Content-Type': 'application/json',
				'OCS-APIRequest': 'true',
				requesttoken: document.head.dataset.requesttoken || '',
			},
			body: JSON.stringify({ languages: auto.checked ? 'auto' : choisies.join(',') }),
		})
			.then(function (reponse) {
				if (!reponse.ok) {
					throw new Error(String(reponse.status))
				}
				return reponse.json()
			})
			.then(function (charge) {
				const donnees = charge.ocs.data

				// The server has the last word: it drops whatever it does not know. So
				// the chips are redrawn from its response, not from what was entered.
				liste.innerHTML = ''
				donnees.effective.forEach(ajouterChip)
				rafraichirChoix()

				// The reindex reminder only shows up when it applies: kept on screen
				// permanently, it would be nothing but noise.
				retour.textContent = donnees.rebuilt
					? racine.dataset.msgReindex
					: racine.dataset.msgSaved
			})
			.catch(function () {
				retour.textContent = racine.dataset.msgError
			})
			.then(function () {
				bouton.disabled = false
			})
	})

	basculerMode()
	rafraichirChoix()
})
