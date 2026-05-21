<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

// Hidden form. The "Upload .md" button in list.php triggers the native
// browser file picker (multi-select); once files are selected, the form
// auto-submits. Collisions always return an error notice — there is no
// replace/rename affordance from the admin UI (the `skill-write` AI
// ability still has `on_conflict` for agent-driven flows).
?>
<form
    id="novamira-skills-upload-form"
    method="post"
    enctype="multipart/form-data"
    action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
    class="novamira-skills-upload-form"
>
    <?php wp_nonce_field('novamira_skill_upload'); ?>
    <input type="hidden" name="action" value="novamira_skill_upload" />
    <input type="hidden" name="on_conflict" value="rename" />
    <input
        type="file"
        name="skill_file[]"
        id="novamira-skills-upload-file"
        accept=".md"
        multiple
    />
</form>
