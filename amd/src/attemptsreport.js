// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * AMD module for mod_playercross attempts-report page interactions.
 *
 * Provides select-all, bulk delete and single-row delete via a shared form — the same
 * pattern mod_playercross/managewords already uses for its own bulk actions, trimmed
 * down to delete-only (no approve concept here).
 *
 * @module     mod_playercross/attemptsreport
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['core/modal_save_cancel', 'core/modal_events', 'core/str'], function(ModalSaveCancel, ModalEvents, Str) {
    'use strict';

    let bulkForm = null;
    let bulkActionField = null;
    let selectAllCheckbox = null;
    let bulkDeleteBtn = null;

    /**
     * Refreshes the bulk-delete button state and label based on current selection.
     */
    const updateBulkButton = () => {
        const totalCount = document.querySelectorAll('.playercross-attempts-bulk-check:checked').length;
        if (bulkDeleteBtn) {
            bulkDeleteBtn.disabled = totalCount === 0;
            bulkDeleteBtn.textContent = `${bulkDeleteBtn.dataset.labelbase} (${totalCount})`;
        }
    };

    /**
     * Opens the Moodle save/cancel modal then calls onConfirm when the user confirms.
     *
     * Never passes show: true to create() — that would render the modal, with core/
     * modal_save_cancel's own default "Save changes" button, before get_string('yes')
     * has resolved (a real, visible gap on a first, uncached call), reading as a wrong
     * dialog flashing before the real one settles — same fix already applied in
     * mod_playercross/managewords and mod_playerwords's equivalent modals.
     *
     * @param {string} title Modal title string.
     * @param {string} body Modal body string.
     * @param {Function} onConfirm Called when the user clicks the save button.
     */
    const showModal = async(title, body, onConfirm) => {
        try {
            const [modal, yesStr] = await Promise.all([
                ModalSaveCancel.create({
                    title: title,
                    body: body,
                    removeOnClose: true,
                }),
                Str.get_string('yes', 'core'),
            ]);
            modal.setSaveButtonText(yesStr);
            modal.getRoot().on(ModalEvents.save, () => onConfirm());
            modal.show();
        } catch (error) {
            window.console.error(error);
        }
    };

    /**
     * Wires up the "select all" checkbox and keeps indeterminate state in sync.
     */
    const initSelectAll = () => {
        selectAllCheckbox = document.getElementById('playercross-attempts-select-all');
        if (!selectAllCheckbox) {
            return;
        }

        selectAllCheckbox.addEventListener('change', () => {
            document.querySelectorAll('.playercross-attempts-bulk-check').forEach((cb) => {
                cb.checked = selectAllCheckbox.checked;
            });
            updateBulkButton();
        });

        document.querySelectorAll('.playercross-attempts-bulk-check').forEach((cb) => {
            cb.addEventListener('change', () => {
                const total = document.querySelectorAll('.playercross-attempts-bulk-check').length;
                const checked = document.querySelectorAll('.playercross-attempts-bulk-check:checked').length;
                selectAllCheckbox.checked = checked === total;
                selectAllCheckbox.indeterminate = checked > 0 && checked < total;
                updateBulkButton();
            });
        });
    };

    /**
     * Wires up the bulk-delete button.
     */
    const initBulkDelete = () => {
        bulkForm = document.getElementById('playercross-attempts-form');
        bulkActionField = document.getElementById('playercross-bulk-action');
        bulkDeleteBtn = document.getElementById('playercross-attempts-bulk-delete-btn');

        if (!bulkForm || !bulkDeleteBtn) {
            return;
        }

        bulkDeleteBtn.addEventListener('click', () => {
            showModal(
                bulkDeleteBtn.dataset.title,
                bulkDeleteBtn.dataset.confirm,
                () => {
                    if (bulkActionField) {
                        bulkActionField.value = 'delete';
                    }
                    bulkForm.submit();
                }
            );
        });
    };

    /**
     * Attaches a click handler to each single-row delete button.
     *
     * Unchecks all checkboxes, checks only the clicked row, then shows a confirmation
     * modal and submits with bulkaction=delete on confirmation.
     */
    const initSingleDelete = () => {
        document.querySelectorAll('.playercross-attempts-single-delete-btn').forEach((btn) => {
            btn.addEventListener('click', () => {
                const attemptid = btn.dataset.attemptid;
                document.querySelectorAll('.playercross-attempts-bulk-check').forEach((cb) => {
                    cb.checked = cb.value === attemptid;
                });
                if (selectAllCheckbox) {
                    selectAllCheckbox.checked = false;
                    selectAllCheckbox.indeterminate = false;
                }
                showModal(
                    btn.dataset.title,
                    btn.dataset.confirm,
                    () => {
                        if (bulkActionField) {
                            bulkActionField.value = 'delete';
                        }
                        bulkForm.submit();
                    }
                );
            });
        });
    };

    return {
        /**
         * Initialises the attempts-report page interactions.
         */
        init: function() {
            initSelectAll();
            initBulkDelete();
            initSingleDelete();
        },
    };
});
