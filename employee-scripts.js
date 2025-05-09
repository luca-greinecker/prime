/**
 * employee-scripts.js
 * Enthält alle JavaScript-Funktionen für die Mitarbeiterdetailseite
 */

document.addEventListener('DOMContentLoaded', function () {
    // DOM-Elemente
    const elements = {
        form: document.getElementById('employeeForm'),
        resultModal: document.getElementById('resultModal'),
        confirmDeleteTrainingModal: document.getElementById('confirmDeleteTrainingModal'),
        deleteTrainingButtons: document.querySelectorAll('.delete-training-btn'),
        groupSelect: document.getElementById('gruppe'),
        areaSelect: document.getElementById('position'),
        teamSelect: document.getElementById('crew'),
        lohnschemaSelect: document.getElementById('lohnschema'),
        productionFields: document.getElementById('production-fields'),
        techFields: document.getElementById('tech-fields'),
        generalZulage: document.getElementById('general-zulage'),
        techBonusCheckboxes: [
            document.getElementById('tk_qualifikationsbonus_1'),
            document.getElementById('tk_qualifikationsbonus_2'),
            document.getElementById('tk_qualifikationsbonus_3'),
            document.getElementById('tk_qualifikationsbonus_4')
        ].filter(el => el !== null),
        pr_lehrabschluss: document.getElementById('pr_lehrabschluss'),
        pr_anfangslohn: document.getElementById('pr_anfangslohn'),
        pr_grundlohn: document.getElementById('pr_grundlohn'),
        pr_qualifikationsbonus: document.getElementById('pr_qualifikationsbonus'),
        pr_expertenbonus: document.getElementById('pr_expertenbonus'),
    };

    // Konfigurationswerte
    const config = {
        initialArea: document.querySelector('#position option:checked')?.value || "",
        isTrainingsmanager: document.body.dataset.isTrainingsmanager === 'true',
        isEhsmanager: document.body.dataset.isEhsmanager === 'true',
        isHr: document.body.dataset.isHr === 'true',
        isSm: document.body.dataset.isSm === 'true',
        isSmstv: document.body.dataset.isSmstv === 'true',
        isEmpfang: document.body.dataset.isEmpfang === 'true'
    };

    function updateAreaOptions() {
        if (!elements.groupSelect) return;
        const selectedGroup = elements.groupSelect.value;

        if (elements.teamSelect) {
            if (selectedGroup === "Schichtarbeit") {
                elements.teamSelect.disabled = !config.isHr;
                const placeholderOption = Array.from(elements.teamSelect.options).find(option => option.value === "---");
                if (placeholderOption) {
                    placeholderOption.disabled = true;
                    if (elements.teamSelect.value === "---" && config.isHr) {
                        const firstValidOption = Array.from(elements.teamSelect.options).find(option => option.value !== "---");
                        if (firstValidOption) {
                            elements.teamSelect.value = firstValidOption.value;
                        }
                    }
                }
                const crewContainer = document.getElementById('crew-container');
                if (crewContainer) {
                    crewContainer.classList.add('highlight-required');
                }
            } else {
                elements.teamSelect.disabled = true;
                elements.teamSelect.value = "---";
                const placeholderOption = Array.from(elements.teamSelect.options).find(option => option.value === "---");
                if (placeholderOption) {
                    placeholderOption.disabled = false;
                }
                const crewContainer = document.getElementById('crew-container');
                if (crewContainer) {
                    crewContainer.classList.remove('highlight-required');
                }
            }
        }

        if (elements.areaSelect) {
            const encodedGroup = encodeURIComponent(selectedGroup);
            fetch(`get_positionen_by_gruppe.php?gruppe=${encodedGroup}`)
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Fehler beim Abrufen der Positionen');
                    }
                    return response.json();
                })
                .then(data => {
                    elements.areaSelect.innerHTML = '';
                    data.forEach(area => {
                        const option = document.createElement('option');
                        option.value = area.name;
                        option.textContent = area.name;
                        const decodedInitialArea = config.initialArea.replace(/&amp;/g, '&');
                        if (area.name === decodedInitialArea) {
                            option.selected = true;
                        }
                        elements.areaSelect.appendChild(option);
                    });
                    elements.areaSelect.disabled = !config.isHr && !config.isSm;
                })
                .catch(error => {
                    console.error('Fehler:', error);
                    showErrorMessage('Es ist ein Fehler beim Abrufen der Positionen aufgetreten.');
                });
        }
    }

    function showErrorMessage(message) {
        if (elements.resultModal) {
            const messageContainer = document.getElementById('resultMessage');
            if (messageContainer) {
                messageContainer.innerHTML = `<div class="alert alert-danger">${message}</div>`;
                new bootstrap.Modal(elements.resultModal).show();
            } else {
                alert(message);
            }
        } else {
            alert(message);
        }
    }

    function showValidationError(title, message) {
        if (elements.resultModal) {
            const messageContainer = document.getElementById('resultMessage');
            if (messageContainer) {
                messageContainer.innerHTML = `
                    <div class="alert alert-danger">
                        <h5 class="alert-heading">${title}</h5>
                        <p>${message}</p>
                    </div>`;
                new bootstrap.Modal(elements.resultModal).show();
            } else {
                alert(`${title}: ${message}`);
            }
        } else {
            alert(`${title}: ${message}`);
        }
    }

    function toggleManagerFieldsNoSalary() {
        if (config.isTrainingsmanager || config.isEhsmanager || config.isEmpfang) {
            if (elements.productionFields) elements.productionFields.style.display = 'none';
            if (elements.techFields) elements.techFields.style.display = 'none';
            if (elements.generalZulage) elements.generalZulage.style.display = 'none';
        }
    }

    function toggleFields() {
        if (!elements.lohnschemaSelect) return;
        const selectedLohnschema = elements.lohnschemaSelect.value;
        toggleManagerFieldsNoSalary();
        if (!config.isTrainingsmanager && !config.isEhsmanager && !config.isEmpfang) {
            if (elements.productionFields) {
                elements.productionFields.style.display = (selectedLohnschema === 'Produktion') ? 'block' : 'none';
            }
            if (elements.techFields) {
                elements.techFields.style.display = (selectedLohnschema === 'Technik') ? 'block' : 'none';
            }
            if (elements.generalZulage) {
                elements.generalZulage.style.display =
                    (selectedLohnschema === 'Produktion' || selectedLohnschema === 'Technik') ? 'block' : 'none';
            }
        }
    }

    function updateTechBonusAvailability() {
        if (!elements.techBonusCheckboxes.length) return;
        if (config.isHr) {
            elements.techBonusCheckboxes.forEach((checkbox, index) => {
                if (!checkbox) return;
                if (index === 0) {
                    checkbox.disabled = false;
                } else {
                    checkbox.disabled = !elements.techBonusCheckboxes[index - 1].checked;
                }
            });
        }
    }

    function updateProductionAvailability() {
        const {pr_lehrabschluss, pr_anfangslohn, pr_grundlohn, pr_qualifikationsbonus, pr_expertenbonus} = elements;
        if (!pr_lehrabschluss || !pr_anfangslohn || !pr_grundlohn || !pr_qualifikationsbonus || !pr_expertenbonus) {
            return;
        }
        if (config.isHr) {
            pr_lehrabschluss.disabled = false;
            pr_anfangslohn.disabled = false;
            pr_grundlohn.disabled = false;
            pr_qualifikationsbonus.disabled = !pr_grundlohn.checked;
            pr_expertenbonus.disabled = !pr_qualifikationsbonus.checked || pr_qualifikationsbonus.disabled;
        }
    }

    function updateHiddenLohnFields() {
        const anfangslohnRadio = document.getElementById('pr_anfangslohn');
        const grundlohnRadio = document.getElementById('pr_grundlohn');
        const hiddenAnfangslohn = document.getElementById('hidden_pr_anfangslohn');
        const hiddenGrundlohn = document.getElementById('hidden_pr_grundlohn');
        if (anfangslohnRadio && grundlohnRadio && hiddenAnfangslohn && hiddenGrundlohn) {
            hiddenAnfangslohn.value = anfangslohnRadio.checked ? '1' : '0';
            hiddenGrundlohn.value = grundlohnRadio.checked ? '1' : '0';
            anfangslohnRadio.addEventListener('change', () => {
                hiddenAnfangslohn.value = anfangslohnRadio.checked ? '1' : '0';
                hiddenGrundlohn.value = '0';
            });
            grundlohnRadio.addEventListener('change', () => {
                hiddenGrundlohn.value = grundlohnRadio.checked ? '1' : '0';
                hiddenAnfangslohn.value = '0';
            });
        }
    }

    function setupProductionEvents() {
        const {
            techBonusCheckboxes,
            pr_anfangslohn,
            pr_grundlohn,
            pr_qualifikationsbonus,
            pr_expertenbonus,
            lohnschemaSelect
        } = elements;
        const checkboxes = [
            ...techBonusCheckboxes.filter(el => el !== null),
            pr_qualifikationsbonus,
            pr_expertenbonus
        ].filter(el => el !== null);
        checkboxes.forEach(checkbox => {
            checkbox.addEventListener('change', () => {
                updateTechBonusAvailability();
                updateProductionAvailability();
            });
        });
        if (pr_anfangslohn && pr_grundlohn) {
            pr_anfangslohn.addEventListener('change', () => {
                updateProductionAvailability();
            });
            pr_grundlohn.addEventListener('change', () => {
                updateProductionAvailability();
            });
        }
        if (lohnschemaSelect) {
            lohnschemaSelect.addEventListener('change', () => {
                toggleFields();
                updateTechBonusAvailability();
                updateProductionAvailability();
            });
        }
    }

    function setupFieldAccess() {
        if (!config.isHr) {
            const elementsToDisable = [
                document.getElementById('gender'),
                document.getElementById('lohnschema'),
                document.getElementById('gruppe'),
                document.getElementById('ln_zulage'),
                document.getElementById('phone_number'),
                document.getElementById('leasing'),
                document.getElementById('ersthelfer'),
                document.getElementById('svp'),
                document.getElementById('brandschutzwart'),
                document.getElementById('sprinklerwart')
            ];
            elementsToDisable.forEach(el => {
                if (el) el.disabled = true;
            });
            if (elements.techBonusCheckboxes) {
                elements.techBonusCheckboxes.forEach(cb => {
                    if (cb) cb.disabled = true;
                });
            }
            if (elements.pr_lehrabschluss) elements.pr_lehrabschluss.disabled = true;
            if (elements.pr_anfangslohn) elements.pr_anfangslohn.disabled = true;
            if (elements.pr_grundlohn) elements.pr_grundlohn.disabled = true;
            if (elements.pr_qualifikationsbonus) elements.pr_qualifikationsbonus.disabled = true;
            if (elements.pr_expertenbonus) elements.pr_expertenbonus.disabled = true;
        } else {
            if (elements.lohnschemaSelect) elements.lohnschemaSelect.disabled = false;
        }
        if (elements.teamSelect && elements.groupSelect) {
            const isSchichtarbeit = elements.groupSelect.value === "Schichtarbeit";
            elements.teamSelect.disabled = !(config.isHr && isSchichtarbeit);
            if (isSchichtarbeit) {
                const placeholderOption = Array.from(elements.teamSelect.options).find(option => option.value === "---");
                if (placeholderOption) {
                    placeholderOption.disabled = true;
                }
            }
        }
        if (elements.areaSelect) {
            elements.areaSelect.disabled = !(config.isHr || config.isSm);
        }

        // Spezielle Behandlung für Empfangsmitarbeiter - können nur badge_id bearbeiten
        if (config.isEmpfang && !config.isHr) {
            const badgeIdField = document.getElementById('badge_id');
            if (badgeIdField) {
                badgeIdField.disabled = false;
            }

            // Alle anderen Felder deaktivieren
            const allInputs = document.querySelectorAll('input, select, textarea');
            allInputs.forEach(input => {
                if (input.id !== 'badge_id' && input.type !== 'hidden' && input.id !== 'employee_photo' && input.id !== 'remove_photo') {
                    input.disabled = true;
                }
            });
        }
    }

    function validateForm(event) {
        // Wenn es sich um einen Empfangsmitarbeiter handelt, müssen wir nur die badge_id validieren
        if (config.isEmpfang && !config.isHr) {
            const badgeIdField = document.getElementById('badge_id');
            if (badgeIdField && (!badgeIdField.value || isNaN(parseInt(badgeIdField.value)))) {
                event.preventDefault();
                showValidationError("Ungültige Ausweisnummer",
                    "Bitte geben Sie eine gültige Ausweisnummer ein.");
                badgeIdField.focus();
                badgeIdField.classList.add('is-invalid');
                return false;
            }
            return true;
        }

        // Normale Validierung für andere Benutzer
        const gruppeSelect = document.getElementById('gruppe');
        const teamSelect = document.getElementById('crew');
        if (gruppeSelect && teamSelect && gruppeSelect.value === "Schichtarbeit") {
            if (teamSelect.value === "---") {
                event.preventDefault();
                showValidationError("Bitte wählen Sie ein Team aus",
                    "Bei der Gruppe 'Schichtarbeit' muss ein Team ausgewählt werden.");
                teamSelect.focus();
                teamSelect.classList.add('is-invalid');
                const crewContainer = document.getElementById('crew-container');
                if (crewContainer) {
                    crewContainer.classList.add('highlight-required');
                }
                return false;
            }
        }
        return true;
    }

    function setupFormSubmission() {
        const {form} = elements;
        if (!form) return;
        form.addEventListener('submit', validateForm);
        form.addEventListener('submit', function (event) {
            if (event.defaultPrevented) {
                return;
            }
            event.preventDefault();
            const submitBtn = form.querySelector('button[type="submit"]');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Speichern...';
            }

            // Form-Daten vorbereiten - für Empfangsmitarbeiter nur badge_id mitschicken
            let formData;
            if (config.isEmpfang && !config.isHr) {
                formData = new FormData();
                formData.append('id', form.querySelector('input[name="id"]').value);
                formData.append('badge_id', form.querySelector('input[name="badge_id"]').value);
            } else {
                formData = new FormData(form);
            }

            fetch('update_employee.php', {
                method: 'POST',
                body: formData
            })
                .then(response => response.text())
                .then(data => {
                    document.getElementById('resultMessage').innerHTML = data;
                    new bootstrap.Modal(elements.resultModal).show();
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = '<i class="bi bi-save"></i> ' +
                            (config.isEmpfang && !config.isHr ? 'Ausweisnummer speichern' : 'Änderungen speichern');
                    }
                })
                .catch(error => {
                    document.getElementById('resultMessage').innerHTML =
                        '<div class="alert alert-danger">Fehler beim Speichern: ' + error.message + '</div>';
                    new bootstrap.Modal(elements.resultModal).show();
                    console.error('Error:', error);
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = '<i class="bi bi-save"></i> ' +
                            (config.isEmpfang && !config.isHr ? 'Ausweisnummer speichern' : 'Änderungen speichern');
                    }
                });
        });
    }

    function setupTrainingDeletion() {
        const {deleteTrainingButtons, confirmDeleteTrainingModal} = elements;
        if (deleteTrainingButtons.length > 0 && confirmDeleteTrainingModal) {
            const deleteModal = new bootstrap.Modal(confirmDeleteTrainingModal);
            deleteTrainingButtons.forEach(button => {
                button.addEventListener('click', function (event) {
                    event.preventDefault();
                    const trainingId = this.getAttribute('data-training-id');
                    const trainingDisplayId = this.getAttribute('data-training-display-id');
                    const trainingName = this.getAttribute('data-training-name');
                    const trainingDate = this.getAttribute('data-training-date');
                    const deleteUrl = this.getAttribute('data-delete-url');
                    document.getElementById('training-id-display').textContent = trainingDisplayId || trainingId;
                    document.getElementById('training-name-display').textContent = trainingName;
                    document.getElementById('training-date-display').textContent = trainingDate;
                    document.getElementById('confirm-delete-training-link').setAttribute('href', deleteUrl);
                    deleteModal.show();
                });
            });
        }
    }

    function init() {
        toggleFields();
        setupProductionEvents();
        setupFieldAccess();
        updateTechBonusAvailability();
        updateProductionAvailability();
        updateHiddenLohnFields();
        if (elements.groupSelect) {
            elements.groupSelect.addEventListener('change', updateAreaOptions);
            updateAreaOptions();
        }
        setupFormSubmission();
        setupTrainingDeletion();

        const hasResultMessage = document.body.dataset.hasResultMessage === 'true';
        if (hasResultMessage && elements.resultModal) {
            const modalInstance = new bootstrap.Modal(elements.resultModal);
            modalInstance.show();
            // Nach dem Anzeigen des Modals zurücksetzen
            document.body.dataset.hasResultMessage = 'false';
            elements.resultModal.addEventListener('hidden.bs.modal', function () {
                document.getElementById('resultMessage').innerHTML = "";
            });
        }
    }

    // Falls die Seite aus dem bfcache geladen wird, den Flag zurücksetzen
    window.addEventListener('pageshow', function (event) {
        if (event.persisted) {
            document.body.dataset.hasResultMessage = 'false';
        }
    });

    init();
});