<?php
/**
 * SPDX-FileCopyrightText: 2026 Alain Lesage
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use OCP\Util;

Util::addScript('fulltextsearch_postgresql', 'settings-admin');
Util::addStyle('fulltextsearch_postgresql', 'settings-admin');

/** @var array{available: string[], selected: string[], raw: string, max: int, saveUrl: string} $_ */
$available = $_['available'];
$selected = $_['selected'];
$auto = $_['raw'] === 'auto';
?>
<div id="fulltextsearch_postgresql" class="section"
	data-save-url="<?php p($_['saveUrl']); ?>"
	data-msg-saved="<?php p($l->t('Saved.')); ?>"
	data-msg-reindex="<?php p($l->t('The index has been rebuilt. To fill it again, run: occ fulltextsearch:reset && occ fulltextsearch:index')); ?>"
	data-msg-pick="<?php p($l->t('Add at least one language.')); ?>"
	data-msg-error="<?php p($l->t('Could not save.')); ?>"
	data-msg-remove="<?php p($l->t('Remove')); ?>"
	data-max="<?php p($_['max']); ?>">

	<h2><?php p($l->t('Full text search with PostgreSQL')); ?></h2>

	<?php if ($available === []): ?>
		<p class="settings-hint">
			<?php p($l->t('Cannot read the text search configurations. Is this Nextcloud running on PostgreSQL?')); ?>
		</p>
	<?php else: ?>
		<p class="settings-hint">
			<?php p($l->t('The language decides how words are reduced to their stem, which accents are folded, and which words are ignored.')); ?>
		</p>

		<p>
			<input type="checkbox" id="ftspg-auto" class="checkbox" <?php if ($auto) { ?>checked<?php } ?>>
			<label for="ftspg-auto"><?php p($l->t('Follow the language of this Nextcloud instance')); ?></label>
		</p>

		<div id="ftspg-manual" class="ftspg-manual"
			aria-disabled="<?php p($auto ? 'true' : 'false'); ?>">
			<label class="ftspg-label" for="ftspg-add"><?php p($l->t('Indexing languages')); ?></label>

			<ul id="ftspg-chips" class="ftspg-chips" aria-live="polite">
				<?php foreach ($selected as $langue): ?>
					<li class="ftspg-chip" data-lang="<?php p($langue); ?>">
						<span><?php p($langue); ?></span>
						<button type="button" class="ftspg-chip-remove"
							aria-label="<?php p($l->t('Remove')); ?>">&times;</button>
					</li>
				<?php endforeach; ?>
			</ul>

			<select id="ftspg-add">
				<option value=""><?php p($l->t('Add a language…')); ?></option>
				<?php foreach ($available as $langue): ?>
					<option value="<?php p($langue); ?>"><?php p($langue); ?></option>
				<?php endforeach; ?>
			</select>

			<p class="settings-hint ftspg-cost">
				<?php p($l->t('Add a second one only for a genuinely bilingual corpus: each language grows the index by roughly 20%%.')); ?>
			</p>
		</div>

		<p>
			<button id="ftspg-save" class="primary"><?php p($l->t('Save')); ?></button>
			<span id="ftspg-feedback" class="ftspg-feedback" role="status"></span>
		</p>
	<?php endif; ?>
</div>
