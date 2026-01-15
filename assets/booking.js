/**
 * CalDAV Booking - Frontend JavaScript
 * Version 2.1 - Dynamic Meeting Types Loading
 */
(function($) {
    'use strict';
    
    class CalDAVBooking {
        constructor(container) {
            this.container = container;
            this.$container = $(container);
            this.daysToShow = parseInt(this.$container.data('days')) || 14;
            this.currentWeekOffset = 0;
            this.selectedDate = null;
            this.selectedTime = null;
            this.selectedDuration = null;
            this.selectedType = null;
            this.daysPerPage = 7;
            this.meetingTypes = [];
            this.availabilityCache = {};
            
            this.init();
        }
        
        init() {
            this.debug('Initializing CalDAV Booking');
            // Load meeting types via AJAX (bypasses page cache)
            this.loadMeetingTypes();
            this.bindEvents();
        }

        debug(message, data = null) {
            if (caldavBooking.debug) {
                if (data !== null) {
                    console.log('[CalDAV]', message, data);
                } else {
                    console.log('[CalDAV]', message);
                }
            }
        }
        
        loadMeetingTypes() {
            const self = this;
            
            $.ajax({
                url: caldavBooking.ajaxurl,
                type: 'POST',
                data: {
                    action: 'caldav_get_meeting_types',
                    nonce: caldavBooking.nonce
                },
                success: function(response) {
                    self.debug('Meeting types loaded', response.data.types);
                    if (response.success && response.data.types) {
                        self.meetingTypes = response.data.types;
                        self.renderMeetingTypes();
                    } else {
                        self.$container.find('.caldav-loading-types').text('Fehler beim Laden');
                    }
                },
                error: function(xhr, status, error) {
                    self.debug('Meeting types error', { status, error });
                    self.$container.find('.caldav-loading-types').text('Verbindungsfehler');
                }
            });
        }
        
        renderMeetingTypes() {
            const self = this;
            const $container = this.$container.find('.caldav-meeting-types');
            $container.empty();
            
            if (this.meetingTypes.length === 0) {
                $container.html('<p>Keine Terminarten verfügbar.</p>');
                return;
            }
            
            // If only one type, auto-select and skip to dates
            if (this.meetingTypes.length === 1) {
                this.selectedDuration = this.meetingTypes[0].duration;
                this.selectedType = this.meetingTypes[0].name;
                
                // Hide step 0, update titles and go to step 1
                this.$container.find('.caldav-step[data-step="0"]').hide();
                this.$container.find('.caldav-back-to-types').hide();
                this.$container.find('.caldav-step-title-date').text('1. Datum wählen');
                this.$container.find('.caldav-step-title-time').text('2. Uhrzeit wählen');
                this.$container.find('.caldav-step-title-contact').text('3. Kontaktdaten');
                
                this.goToStep(1);
                this.renderDates();
                return;
            }
            
            // Render multiple types
            this.meetingTypes.forEach(function(type) {
                const descHtml = type.description ? 
                    `<span class="type-desc">${self.escapeHtml(type.description).replace(/\n/g, '<br>')}</span>` : '';
                
                const $btn = $(`
                    <button type="button" class="caldav-type-btn" 
                            data-duration="${type.duration}"
                            data-name="${self.escapeHtml(type.name)}"
                            style="border-color: ${type.color}">
                        <span class="type-name">${self.escapeHtml(type.name)}</span>
                        <span class="type-duration">${type.duration} Minuten</span>
                        ${descHtml}
                    </button>
                `);
                
                $container.append($btn);
            });
            
            this.renderDates();
        }
        
        escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        bindEvents() {
            const self = this;
            
            // Meeting type selection
            this.$container.on('click', '.caldav-type-btn', function() {
                self.selectMeetingType(
                    parseInt($(this).data('duration')),
                    $(this).data('name')
                );
            });
            
            // Date selection
            this.$container.on('click', '.caldav-date-btn:not(:disabled)', function() {
                self.selectDate($(this).data('date'));
            });
            
            // Time slot selection
            this.$container.on('click', '.caldav-slot-btn', function() {
                self.selectTime($(this).data('time'), $(this).data('display'));
            });
            
            // Navigation
            this.$container.on('click', '.caldav-prev-week', function() {
                self.navigateWeek(-1);
            });
            
            this.$container.on('click', '.caldav-next-week', function() {
                self.navigateWeek(1);
            });
            
            // Back buttons
            this.$container.on('click', '.caldav-back-btn', function() {
                self.goToStep(parseInt($(this).data('to')));
            });
            
            // Form submission
            this.$container.on('submit', '.caldav-booking-form', function(e) {
                e.preventDefault();
                self.submitBooking();
            });
            
            // New booking button
            this.$container.on('click', '.caldav-new-booking-btn', function() {
                self.reset();
            });
        }
        
        selectMeetingType(duration, name) {
            this.selectedDuration = duration;
            this.selectedType = name;
            
            // Update UI
            this.$container.find('.caldav-type-btn').removeClass('selected');
            this.$container.find(`.caldav-type-btn[data-duration="${duration}"]`).addClass('selected');
            
            this.goToStep(1);
        }
        
        renderDates() {
            const $datesContainer = this.$container.find('.caldav-dates');
            $datesContainer.empty();
            
            const today = new Date();
            today.setHours(0, 0, 0, 0);
            
            const startDate = new Date(today);
            startDate.setDate(startDate.getDate() + (this.currentWeekOffset * this.daysPerPage));
            
            const dayNames = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];
            const monthNames = ['Jan', 'Feb', 'Mär', 'Apr', 'Mai', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dez'];
            
            const datesToCheck = [];
            
            for (let i = 0; i < this.daysPerPage; i++) {
                const date = new Date(startDate);
                date.setDate(date.getDate() + i);
                
                const dateStr = this.formatDateISO(date);
                const dayNum = date.getDate();
                const dayName = dayNames[date.getDay()];
                const monthName = monthNames[date.getMonth()];
                
                const dayIndex = (this.currentWeekOffset * this.daysPerPage) + i;
                const isPast = date < today;
                const isBeyondLimit = dayIndex >= this.daysToShow;
                const isDisabled = isPast || isBeyondLimit;
                const isSelected = this.selectedDate === dateStr;
                
                const $btn = $(`
                    <button type="button" class="caldav-date-btn ${isSelected ? 'selected' : ''} ${!isDisabled ? 'caldav-date-loading' : ''}" 
                            data-date="${dateStr}" ${isDisabled ? 'disabled' : ''}>
                        <span class="day-name">${dayName}</span>
                        <span class="day-num">${dayNum}</span>
                        <span class="month">${monthName}</span>
                        <span class="caldav-slot-count"></span>
                    </button>
                `);
                
                $datesContainer.append($btn);
                
                if (!isDisabled) {
                    datesToCheck.push(dateStr);
                }
            }
            
            // Update navigation buttons
            this.$container.find('.caldav-prev-week').prop('disabled', this.currentWeekOffset === 0);
            
            const maxWeeks = Math.ceil(this.daysToShow / this.daysPerPage) - 1;
            this.$container.find('.caldav-next-week').prop('disabled', this.currentWeekOffset >= maxWeeks);
            
            // Check availability for visible dates
            if (datesToCheck.length > 0) {
                this.checkDatesAvailability(datesToCheck);
            }
        }
        
        checkDatesAvailability(dates) {
            const self = this;
            
            $.ajax({
                url: caldavBooking.ajaxurl,
                type: 'POST',
                data: {
                    action: 'caldav_check_dates',
                    nonce: caldavBooking.nonce,
                    dates: dates,
                    duration: this.selectedDuration || 60
                },
                success: function(response) {
                    if (response.success && response.data.availability) {
                        self.updateDateAvailability(response.data.availability);
                    }
                },
                error: function() {
                    // Remove loading state on error
                    self.$container.find('.caldav-date-btn').removeClass('caldav-date-loading');
                }
            });
        }
        
        updateDateAvailability(availability) {
            const self = this;
            
            Object.keys(availability).forEach(function(date) {
                const info = availability[date];
                const $btn = self.$container.find(`.caldav-date-btn[data-date="${date}"]`);
                
                $btn.removeClass('caldav-date-loading');
                
                if (info.available && info.slots > 0) {
                    $btn.addClass('caldav-date-available');
                    $btn.find('.caldav-slot-count').text(info.slots + ' frei');
                } else {
                    $btn.addClass('caldav-date-unavailable');
                    $btn.find('.caldav-slot-count').text('belegt');
                    $btn.prop('disabled', true);
                }
            });
        }
        
        navigateWeek(direction) {
            const maxWeeks = Math.ceil(this.daysToShow / this.daysPerPage) - 1;
            const newOffset = this.currentWeekOffset + direction;
            
            if (newOffset >= 0 && newOffset <= maxWeeks) {
                this.currentWeekOffset = newOffset;
                this.renderDates();
            }
        }
        
        selectDate(dateStr) {
            this.selectedDate = dateStr;
            
            // Update UI
            this.$container.find('.caldav-date-btn').removeClass('selected');
            this.$container.find(`.caldav-date-btn[data-date="${dateStr}"]`).addClass('selected');
            
            // Load available slots
            this.loadSlots(dateStr);
            this.goToStep(2);
        }
        
        loadSlots(dateStr) {
            const self = this;
            const $slotsContainer = this.$container.find('.caldav-slots');
            const $loading = this.$container.find('.caldav-slots-loading');
            const $noSlots = this.$container.find('.caldav-no-slots');

            this.debug('Loading slots for', dateStr);

            $slotsContainer.empty();
            $loading.show();
            $noSlots.hide();
            this.hideError();

            $.ajax({
                url: caldavBooking.ajaxurl,
                type: 'POST',
                data: {
                    action: 'caldav_get_slots',
                    nonce: caldavBooking.nonce,
                    date: dateStr,
                    duration: this.selectedDuration || 60
                },
                success: function(response) {
                    self.debug('Slots loaded', response.data.slots);
                    $loading.hide();

                    if (response.success && response.data.slots.length > 0) {
                        self.renderSlots(response.data.slots);
                    } else if (response.success) {
                        $noSlots.show();
                    } else {
                        self.showError(response.data.message || 'Fehler beim Laden der Termine');
                    }
                },
                error: function() {
                    $loading.hide();
                    self.showError('Verbindungsfehler - bitte versuche es erneut');
                }
            });
        }
        
        renderSlots(slots) {
            const $slotsContainer = this.$container.find('.caldav-slots');
            $slotsContainer.empty();
            
            slots.forEach(slot => {
                const $btn = $(`
                    <button type="button" class="caldav-slot-btn" 
                            data-time="${slot.time}" data-display="${slot.display}">
                        ${slot.display}
                    </button>
                `);
                $slotsContainer.append($btn);
            });
        }
        
        selectTime(time, display) {
            this.selectedTime = time;
            this.selectedDisplay = display;
            
            // Update UI
            this.$container.find('.caldav-slot-btn').removeClass('selected');
            this.$container.find(`.caldav-slot-btn[data-time="${time}"]`).addClass('selected');
            
            // Update selected slot display in form
            const dateFormatted = this.formatDateDisplay(this.selectedDate);
            let slotHtml = `<strong>Gewählter Termin:</strong><br>${dateFormatted}<br>${display}`;
            if (this.selectedType) {
                slotHtml += `<br><em>${this.selectedType}</em>`;
            }
            this.$container.find('.caldav-selected-slot').html(slotHtml);
            
            this.goToStep(3);
        }
        
        submitBooking() {
            const self = this;
            const $form = this.$container.find('.caldav-booking-form');
            const $submitBtn = $form.find('.caldav-submit-btn');
            
            const formData = {
                action: 'caldav_book_slot',
                nonce: caldavBooking.nonce,
                date: this.selectedDate,
                time: this.selectedTime,
                duration: this.selectedDuration || 60,
                meeting_type: this.selectedType || 'Termin',
                name: $form.find('#caldav-name').val(),
                email: $form.find('#caldav-email').val(),
                phone: $form.find('#caldav-phone').val(),
                message: $form.find('#caldav-message').val(),
                website: $form.find('#caldav-website').val() // Honeypot
            };
            
            // Validate
            if (!formData.name || !formData.email) {
                this.showError('Bitte fülle alle Pflichtfelder aus');
                return;
            }
            
            // Basic email validation
            if (!formData.email.match(/^[^\s@]+@[^\s@]+\.[^\s@]+$/)) {
                this.showError('Bitte gib eine gültige E-Mail-Adresse ein');
                return;
            }
            
            $submitBtn.prop('disabled', true).html('<span class="caldav-spinner"></span> Wird gebucht...');
            this.hideError();

            self.debug('Submitting booking', formData);

            $.ajax({
                url: caldavBooking.ajaxurl,
                type: 'POST',
                data: formData,
                timeout: 60000, // 60 Sekunden Timeout
                success: function(response) {
                    self.debug('Booking response', response);
                    $submitBtn.prop('disabled', false).text('Termin buchen');

                    if (response.success) {
                        // Debug-Logs ausgeben wenn vorhanden
                        if (response.data.debug) {
                            console.group('[CalDAV] Booking Debug Log');
                            response.data.debug.forEach(function(entry) {
                                console.log('[CalDAV]', entry.step, entry);
                            });
                            if (response.data.caldav_result) {
                                console.log('[CalDAV] Result:', response.data.caldav_result);
                            }
                            console.groupEnd();
                        }

                        let confirmHtml = `<strong>${response.data.date}</strong><br>${response.data.time}`;
                        if (response.data.type) {
                            confirmHtml += `<br><em>${response.data.type}</em>`;
                        }

                        // Buchungsstatus
                        if (response.data.caldav_result && response.data.caldav_result.success) {
                            confirmHtml += `<br><small style="color:#28a745;">✓ Termin erfolgreich im Kalender eingetragen</small>`;
                        } else if (response.data.caldav_result && !response.data.caldav_result.success) {
                            confirmHtml += `<br><small style="color:#dc3545;">✗ CalDAV Fehler: ${response.data.caldav_result.error}</small>`;
                        } else {
                            confirmHtml += `<br><small style="color:#28a745;">✓ Termin erfolgreich reserviert</small>`;
                        }

                        // E-Mail-Hinweis nur wenn aktiviert
                        if (caldavBooking.emailEnabled) {
                            confirmHtml += `<br><small style="color:#666;">📧 Bestätigung wird per E-Mail zugestellt</small>`;
                        }

                        self.$container.find('.caldav-confirmation-details').html(confirmHtml);
                        self.goToStep(4);
                    } else {
                        self.showError(response.data.message || 'Buchung fehlgeschlagen');
                    }
                },
                error: function(xhr, status, error) {
                    self.debug('Booking error', { status, error, response: xhr.responseText });
                    $submitBtn.prop('disabled', false).text('Termin buchen');
                    if (status === 'timeout') {
                        // Bei Timeout wurde die Buchung wahrscheinlich trotzdem angenommen
                        let confirmHtml = `<strong>${formData.date}</strong><br>${formData.time}`;
                        confirmHtml += `<br><small style="color:#e67e22;">⚠ Verbindung langsam - Ihr Termin wurde wahrscheinlich reserviert.</small>`;
                        if (caldavBooking.emailEnabled) {
                            confirmHtml += `<br><small>Bitte prüfen Sie Ihr E-Mail-Postfach für eine Bestätigung.</small>`;
                        } else {
                            confirmHtml += `<br><small>Bitte prüfen Sie Ihren Kalender.</small>`;
                        }
                        self.$container.find('.caldav-confirmation-details').html(confirmHtml);
                        self.goToStep(4);
                    } else {
                        self.showError('Verbindungsfehler - bitte versuche es erneut');
                    }
                }
            });
        }
        
        goToStep(stepNum) {
            this.$container.find('.caldav-step').removeClass('caldav-step-active');
            this.$container.find(`.caldav-step[data-step="${stepNum}"]`).addClass('caldav-step-active');
        }
        
        reset() {
            this.selectedDate = null;
            this.selectedTime = null;
            this.currentWeekOffset = 0;
            
            // Only reset meeting type if there are multiple
            if (this.meetingTypes.length > 1) {
                this.selectedDuration = null;
                this.selectedType = null;
            }
            
            // Clear form
            this.$container.find('.caldav-booking-form')[0].reset();
            this.$container.find('.caldav-selected-slot').empty();
            this.$container.find('.caldav-slots').empty();
            this.$container.find('.caldav-no-slots').hide();
            
            this.renderDates();
            
            // Go to first step (meeting type if multiple, otherwise date)
            if (this.meetingTypes.length > 1) {
                this.goToStep(0);
            } else {
                this.goToStep(1);
            }
            
            this.hideError();
        }
        
        showError(message) {
            const $error = this.$container.find('.caldav-error-message');
            $error.hide().text(message).fadeIn(200);
        }
        
        hideError() {
            this.$container.find('.caldav-error-message').hide().text('');
        }
        
        formatDateISO(date) {
            const year = date.getFullYear();
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const day = String(date.getDate()).padStart(2, '0');
            return `${year}-${month}-${day}`;
        }
        
        formatDateDisplay(dateStr) {
            const date = new Date(dateStr + 'T00:00:00');
            const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
            return date.toLocaleDateString('de-DE', options);
        }
    }
    
    // Initialize on document ready
    $(document).ready(function() {
        $('.caldav-booking-container').each(function() {
            new CalDAVBooking(this);
        });
    });
    
})(jQuery);
