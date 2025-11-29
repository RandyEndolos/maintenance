<?php
// Reusable calendar component for staff schedule visualization
// Determine the base path for API calls relative to the current script location
$scriptPath = $_SERVER['SCRIPT_NAME'];
$basePath = dirname(dirname($scriptPath));
if ($basePath === '/' || $basePath === '\\' || $basePath === '.') {
    $basePath = '';
} else {
    $basePath = rtrim($basePath, '/\\');
}
$apiPath = ($basePath ? $basePath . '/' : '') . 'api/calendar_data.php';
?>
<div class="calendar-section">
  <h2 class="calendar-section-title">Staff Schedule Calendar</h2>
  <div class="calendar-container">
    <div class="calendar-main">
      <div class="calendar-header">
        <button class="calendar-nav-btn" id="prevMonth" type="button">‹</button>
        <h2 class="calendar-title" id="calendarTitle"></h2>
        <button class="calendar-nav-btn" id="nextMonth" type="button">›</button>
      </div>
      <div class="calendar-grid" id="calendarGrid"></div>
    </div>
    <div class="calendar-sidebar">
      <div class="sidebar-card">
        <h3 class="sidebar-card-title">Available Staff</h3>
        <div class="staff-list" id="availableStaffList"></div>
      </div>
    </div>
  </div>
  <div class="task-modal" id="taskModal" style="display: none;">
    <div class="task-modal-content">
      <div class="task-modal-header">
        <h3 id="modalDate"></h3>
        <button class="task-modal-close" id="closeModal" type="button">×</button>
      </div>
      <div class="task-modal-body" id="modalBody"></div>
    </div>
  </div>
</div>

<style>
.calendar-section {
  margin: 20px 0;
}

.calendar-section-title {
  margin: 0 0 16px;
  font-size: 24px;
  font-weight: 700;
  color: var(--maroon-700, #5a0f1b);
}

.calendar-container {
  display: grid;
  grid-template-columns: minmax(0, 3fr) minmax(240px, 0.6fr);
  gap: 16px;
  margin-top: 20px;
  align-items: start;
}

@media (max-width: 1100px) {
  .calendar-container {
    grid-template-columns: 1fr;
  }
}

.calendar-main {
  background: #fff;
  border: 1px solid #e5e5e5;
  border-radius: 10px;
  padding: 20px;
}

.calendar-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 20px;
}

.calendar-title {
  margin: 0;
  font-size: 20px;
  font-weight: 700;
  color: var(--maroon-700, #5a0f1b);
}

.calendar-nav-btn {
  background: none;
  border: 1px solid #e5e5e5;
  border-radius: 6px;
  width: 36px;
  height: 36px;
  cursor: pointer;
  font-size: 24px;
  color: var(--maroon-700, #5a0f1b);
  display: flex;
  align-items: center;
  justify-content: center;
  transition: background .15s ease, border-color .15s ease;
}

.calendar-nav-btn:hover {
  background: #fff7f8;
  border-color: var(--maroon-400, #a42b43);
}

.calendar-grid {
  display: grid;
  grid-template-columns: repeat(7, 1fr);
  gap: 4px;
}

.calendar-day-header {
  padding: 10px;
  text-align: center;
  font-weight: 600;
  font-size: 12px;
  color: var(--maroon-700, #5a0f1b);
  text-transform: uppercase;
}

.calendar-day {
  aspect-ratio: 1;
  padding: 8px;
  border: 1px solid #e5e5e5;
  border-radius: 6px;
  cursor: pointer;
  position: relative;
  background: #fff;
  transition: background .15s ease, border-color .15s ease;
  min-height: 60px;
  display: flex;
  flex-direction: column;
}

.calendar-day:hover {
  background: #fff7f8;
  border-color: var(--maroon-400, #a42b43);
}

.calendar-day.other-month {
  opacity: 0.3;
  background: #f9f9f9;
}

.calendar-day-number {
  font-weight: 600;
  color: var(--maroon-700, #5a0f1b);
  font-size: 14px;
  margin-bottom: 4px;
}

.calendar-day-indicators {
  display: flex;
  flex-wrap: wrap;
  gap: 2px;
  margin-top: auto;
}

.calendar-day-staff-count {
  font-size: 10px;
  color: var(--maroon-700, #5a0f1b);
  font-weight: 600;
  margin-top: 4px;
  text-align: center;
}

.calendar-indicator {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  flex-shrink: 0;
}

.calendar-day.has-pending {
  border-left: 3px solid #f59e0b;
  background: #fef3c7;
}

.calendar-day.has-progress {
  border-left: 3px solid #3b82f6;
  background: #dbeafe;
}

.calendar-day.has-completed {
  border-left: 3px solid #10b981;
  background: #d1fae5;
}

.calendar-sidebar {
  display: flex;
  flex-direction: column;
  gap: 16px;
  position: sticky;
  top: 20px;
}

.sidebar-card {
  background: #fff;
  border: 1px solid #e5e5e5;
  border-radius: 10px;
  padding: 12px;
}

.sidebar-title,
.sidebar-card-title {
  margin: 0 0 8px;
  font-size: 15px;
  font-weight: 700;
  color: var(--maroon-700, #5a0f1b);
}


.staff-list {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.staff-item {
  padding: 8px 10px;
  border: 1px solid #e5e5e5;
  border-radius: 8px;
  background: #fff;
}

.staff-item-name {
  font-weight: 600;
  color: var(--maroon-700, #5a0f1b);
  font-size: 14px;
  margin-bottom: 2px;
}

.staff-item-area {
  font-size: 11px;
  color: #6b7280;
  margin-bottom: 2px;
}

.staff-item-status {
  display: inline-block;
  padding: 4px 8px;
  border-radius: 4px;
  font-size: 11px;
  font-weight: 600;
  text-transform: uppercase;
}

.staff-item-status.on-duty {
  background: #d1fae5;
  color: #065f46;
}

.staff-item-status.absent {
  background: #fee2e2;
  color: #991b1b;
}

.staff-item-status.on-leave {
  background: #fef3c7;
  color: #92400e;
}

.task-modal {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: rgba(0, 0, 0, 0.5);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
  padding: 20px;
}

.task-modal-content {
  background: #fff;
  border-radius: 10px;
  max-width: 600px;
  width: 100%;
  max-height: 80vh;
  overflow-y: auto;
  box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
}

.task-modal-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 20px;
  border-bottom: 1px solid #e5e5e5;
}

.task-modal-header h3 {
  margin: 0;
  font-size: 20px;
  font-weight: 700;
  color: var(--maroon-700, #5a0f1b);
}

.task-modal-close {
  background: none;
  border: none;
  font-size: 32px;
  line-height: 1;
  cursor: pointer;
  color: #6b7280;
  padding: 0;
  width: 32px;
  height: 32px;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 4px;
  transition: background .15s ease;
}

.task-modal-close:hover {
  background: #f3f4f6;
}

.task-modal-body {
  padding: 20px;
}

.task-item {
  padding: 16px;
  border: 1px solid #e5e5e5;
  border-radius: 8px;
  margin-bottom: 12px;
  background: #fff;
}

.task-item:last-child {
  margin-bottom: 0;
}

.task-item-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 12px;
}

.task-item-title-group {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.task-item-title {
  font-weight: 600;
  color: var(--maroon-700, #5a0f1b);
  font-size: 16px;
}

.task-item-subtitle {
  font-size: 12px;
  color: #6b7280;
}

.task-item-staff {
  font-weight: 600;
  color: var(--maroon-700, #5a0f1b);
  font-size: 16px;
}

.task-item-status {
  padding: 4px 10px;
  border-radius: 4px;
  font-size: 11px;
  font-weight: 600;
  text-transform: uppercase;
}

.task-item-status.pending {
  background: #fef3c7;
  color: #92400e;
}

.task-item-status.progress {
  background: #dbeafe;
  color: #1e40af;
}

.task-item-status.completed {
  background: #d1fae5;
  color: #065f46;
}

.task-item-field {
  margin-bottom: 8px;
}

.task-item-field:last-child {
  margin-bottom: 0;
}

.task-item-label {
  font-size: 12px;
  font-weight: 600;
  color: #6b7280;
  text-transform: uppercase;
  margin-bottom: 4px;
}

.task-item-value {
  font-size: 14px;
  color: #222;
}

.task-item-staff-section {
  margin-bottom: 16px;
  padding-bottom: 16px;
  border-bottom: 1px solid #e5e5e5;
}

.task-staff-list {
  display: flex;
  flex-direction: column;
  gap: 8px;
  margin-top: 8px;
}

.task-staff-item {
  padding: 10px 12px;
  background: #f9f6f7;
  border: 1px solid #e5e5e5;
  border-radius: 6px;
  border-left: 3px solid var(--maroon-400, #a42b43);
}

.task-staff-name {
  font-weight: 600;
  font-size: 15px;
  color: var(--maroon-700, #5a0f1b);
  margin-bottom: 4px;
}

.task-staff-area {
  font-size: 12px;
  color: #6b7280;
  font-style: italic;
  margin-top: 2px;
}

.task-staff-dept {
  font-size: 12px;
  color: #6b7280;
  margin-top: 2px;
}

.task-staff-contact {
  font-size: 11px;
  color: #9ca3af;
  margin-top: 4px;
  font-style: italic;
}
</style>

<script>
(function() {
  let currentDate = new Date();
  let calendarData = null;

  const dayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
  const monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 
    'July', 'August', 'September', 'October', 'November', 'December'];

  async function loadCalendarData() {
    try {
      const response = await fetch('<?php echo htmlspecialchars($apiPath, ENT_QUOTES, 'UTF-8'); ?>');
      calendarData = await response.json();
      renderCalendar();
      renderAvailableStaff();
    } catch (error) {
      console.error('Error loading calendar data:', error);
    }
  }

  function renderCalendar() {
    const year = currentDate.getFullYear();
    const month = currentDate.getMonth();
    
    const firstDay = new Date(year, month, 1);
    const lastDay = new Date(year, month + 1, 0);
    const startDate = new Date(firstDay);
    startDate.setDate(startDate.getDate() - startDate.getDay());
    
    const endDate = new Date(lastDay);
    endDate.setDate(endDate.getDate() + (6 - endDate.getDay()));
    
    document.getElementById('calendarTitle').textContent = 
      `${monthNames[month]} ${year}`;
    
    const grid = document.getElementById('calendarGrid');
    grid.innerHTML = '';
    
    // Day headers
    dayNames.forEach(day => {
      const header = document.createElement('div');
      header.className = 'calendar-day-header';
      header.textContent = day;
      grid.appendChild(header);
    });
    
    // Calendar days
    const current = new Date(startDate);
    while (current <= endDate) {
      const day = new Date(current);
      const dayElement = document.createElement('div');
      dayElement.className = 'calendar-day';
      
      if (day.getMonth() !== month) {
        dayElement.classList.add('other-month');
      }
      
      const dayNumber = document.createElement('div');
      dayNumber.className = 'calendar-day-number';
      dayNumber.textContent = day.getDate();
      dayElement.appendChild(dayNumber);
      
      const dateStr = formatDate(day);
      const dayTasks = getTasksForDate(dateStr);
      
      // Only show indicators for ongoing tasks (not completed)
      const ongoingTasks = dayTasks.filter(t => {
        const status = t.status.toLowerCase().trim();
        return status !== 'completed' && status !== 'done';
      });
      
      if (ongoingTasks.length > 0) {
        const indicators = document.createElement('div');
        indicators.className = 'calendar-day-indicators';
        
        const statuses = new Set(ongoingTasks.map(t => t.status.toLowerCase().trim()));
        const hasPending = Array.from(statuses).some(s => s === 'pending');
        const hasProgress = Array.from(statuses).some(s => 
          s.includes('progress') || s.includes('waiting for staff') || s === 'in-progress' || s.includes('pickup') || s.includes('confirmation')
        );
        
        // Don't show completed indicator since we filter them out
        if (hasPending) {
          dayElement.classList.add('has-pending');
          const ind = document.createElement('div');
          ind.className = 'calendar-indicator';
          ind.style.background = '#f59e0b';
          indicators.appendChild(ind);
        }
        if (hasProgress) {
          dayElement.classList.add('has-progress');
          const ind = document.createElement('div');
          ind.className = 'calendar-indicator';
          ind.style.background = '#3b82f6';
          indicators.appendChild(ind);
        }
        
        dayElement.appendChild(indicators);
        
        // Add staff count indicator if multiple staff are assigned
        const allStaff = new Set();
        ongoingTasks.forEach(task => {
          if (task.staff && task.staff.length > 0) {
            task.staff.forEach(staff => {
              const staffName = typeof staff === 'string' ? staff : (staff.name || '');
              if (staffName) allStaff.add(staffName);
            });
          }
        });
        
        if (allStaff.size > 0) {
          const staffCount = document.createElement('div');
          staffCount.className = 'calendar-day-staff-count';
          staffCount.textContent = `${allStaff.size} staff`;
          dayElement.appendChild(staffCount);
        }
        
        dayElement.addEventListener('click', () => showTaskModal(dateStr, ongoingTasks));
      }
      
      grid.appendChild(dayElement);
      current.setDate(current.getDate() + 1);
    }
  }

  function getTasksForDate(dateStr) {
    if (!calendarData || !calendarData.tasks) return [];
    
    const tasks = [];
    for (const task of calendarData.tasks) {
      // Only show tasks on the acceptance date (date_start)
      // date_start is set when staff accepts the task
      const acceptanceDate = new Date(task.date_start);
      acceptanceDate.setHours(0, 0, 0, 0);
      const check = new Date(dateStr);
      check.setHours(0, 0, 0, 0);
      
      // Only show if the date matches the acceptance date
      // and task is not completed
      const status = task.status.toLowerCase().trim();
      const isCompleted = status === 'completed' || status === 'done';
      
      if (acceptanceDate.getTime() === check.getTime() && !isCompleted) {
        tasks.push(task);
      }
    }
    return tasks;
  }

  function formatDate(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
  }

  function showTaskModal(dateStr, tasks) {
    const modal = document.getElementById('taskModal');
    const modalDate = document.getElementById('modalDate');
    const modalBody = document.getElementById('modalBody');
    
    const date = new Date(dateStr);
    modalDate.textContent = `${monthNames[date.getMonth()]} ${date.getDate()}, ${date.getFullYear()}`;
    
    modalBody.innerHTML = '';
    
    if (tasks.length === 0) {
      modalBody.innerHTML = '<p>No tasks for this date.</p>';
    } else {
      tasks.forEach(task => {
        const taskDiv = document.createElement('div');
        taskDiv.className = 'task-item';
        
        const header = document.createElement('div');
        header.className = 'task-item-header';
        
        const titleGroup = document.createElement('div');
        titleGroup.className = 'task-item-title-group';

        const title = document.createElement('div');
        title.className = 'task-item-title';
        title.textContent = `Task #${task.id || '—'} • ${task.type || 'Work Request'}`;
        titleGroup.appendChild(title);

        if (task.requester) {
          const subtitle = document.createElement('div');
          subtitle.className = 'task-item-subtitle';
          subtitle.textContent = `Requester: ${task.requester}`;
          titleGroup.appendChild(subtitle);
        }

        header.appendChild(titleGroup);

        const statusDiv = document.createElement('div');
        statusDiv.className = 'task-item-status';
        const status = task.status.toLowerCase().trim();
        if (status === 'pending') {
          statusDiv.classList.add('pending');
        } else if (status.includes('progress') || status.includes('waiting for staff') || status === 'in-progress') {
          statusDiv.classList.add('progress');
        } else if (status === 'completed' || status === 'done') {
          statusDiv.classList.add('completed');
        }
        statusDiv.textContent = task.status;
        header.appendChild(statusDiv);
        
        taskDiv.appendChild(header);
        
        // Display staff information prominently
        if (task.staff && task.staff.length > 0) {
          const staffSection = document.createElement('div');
          staffSection.className = 'task-item-staff-section';
          
          const staffLabel = document.createElement('div');
          staffLabel.className = 'task-item-label';
          staffLabel.textContent = 'Assigned Staff';
          staffSection.appendChild(staffLabel);
          
          const staffList = document.createElement('div');
          staffList.className = 'task-staff-list';
          
          task.staff.forEach((staff, index) => {
            const staffItem = document.createElement('div');
            staffItem.className = 'task-staff-item';
            
            // Handle both string (legacy) and object formats
            const staffName = typeof staff === 'string' ? staff : (staff.name || 'Unknown');
            const staffArea = typeof staff === 'object' && staff.area_of_work ? staff.area_of_work : '';
            const staffDept = typeof staff === 'object' && staff.department ? staff.department : '';
            const staffEmail = typeof staff === 'object' && staff.email ? staff.email : '';
            const staffContact = typeof staff === 'object' && staff.contact_number ? staff.contact_number : '';
            const staffUserType = typeof staff === 'object' && staff.user_type ? staff.user_type : '';
            
            // Only show staff users (skip unknown types)
            if (staffUserType && staffUserType !== 'staff' && staffUserType !== 'unknown') {
              return; // Skip non-staff users
            }
            
            const nameSpan = document.createElement('div');
            nameSpan.className = 'task-staff-name';
            nameSpan.textContent = staffName;
            staffItem.appendChild(nameSpan);
            
            // Display department if available
            if (staffDept) {
              const deptSpan = document.createElement('div');
              deptSpan.className = 'task-staff-dept';
              deptSpan.textContent = `Department: ${staffDept}`;
              staffItem.appendChild(deptSpan);
            }
            
            // Display area of work if available
            if (staffArea) {
              const areaSpan = document.createElement('div');
              areaSpan.className = 'task-staff-area';
              areaSpan.textContent = `Area: ${staffArea}`;
              staffItem.appendChild(areaSpan);
            }
            
            // Display contact info if available
            if (staffEmail || staffContact) {
              const contactDiv = document.createElement('div');
              contactDiv.className = 'task-staff-contact';
              const contactParts = [];
              if (staffEmail) contactParts.push(`Email: ${staffEmail}`);
              if (staffContact) contactParts.push(`Contact: ${staffContact}`);
              contactDiv.textContent = contactParts.join(' | ');
              staffItem.appendChild(contactDiv);
            }
            
            staffList.appendChild(staffItem);
          });
          
          staffSection.appendChild(staffList);
          taskDiv.appendChild(staffSection);
        } else {
          const staffSection = document.createElement('div');
          staffSection.className = 'task-item-staff-section';
          const staffLabel = document.createElement('div');
          staffLabel.className = 'task-item-label';
          staffLabel.textContent = 'Assigned Staff';
          staffSection.appendChild(staffLabel);
          const unassigned = document.createElement('div');
          unassigned.className = 'task-item-value';
          unassigned.textContent = 'Unassigned';
          staffSection.appendChild(unassigned);
          taskDiv.appendChild(staffSection);
        }
        
        const fields = [
          { label: 'Description', value: task.description || 'N/A' },
          { label: 'Location', value: task.location || 'N/A' },
          { label: 'Start Date & Time', value: formatTaskDateTime(task.date_start, task.time_start) },
          { label: 'Duration', value: formatDurationText(task) },
          { label: 'Scheduled Time', value: formatTaskTime(task.time_start, task.time_finish) }
        ];
        
        fields.forEach(field => {
          const fieldDiv = document.createElement('div');
          fieldDiv.className = 'task-item-field';
          
          const label = document.createElement('div');
          label.className = 'task-item-label';
          label.textContent = field.label;
          
          const value = document.createElement('div');
          value.className = 'task-item-value';
          value.textContent = field.value;
          
          fieldDiv.appendChild(label);
          fieldDiv.appendChild(value);
          taskDiv.appendChild(fieldDiv);
        });
        
        modalBody.appendChild(taskDiv);
      });
    }
    
    modal.style.display = 'flex';
  }

  function formatTaskTime(timeStart, timeFinish) {
    if (!timeStart && !timeFinish) return 'N/A';
    const parts = [];
    if (timeStart) parts.push(timeStart);
    if (timeFinish) parts.push(timeFinish);
    return parts.join(' - ') || 'N/A';
  }

  function formatTaskDateTime(dateStr, timeStr) {
    if (!dateStr && !timeStr) return 'N/A';
    if (!dateStr) return timeStr || 'N/A';
    try {
      const iso = `${dateStr}${timeStr ? 'T' + timeStr : 'T00:00:00'}`;
      const dt = new Date(iso);
      if (!isNaN(dt.valueOf())) {
        return dt.toLocaleString(undefined, {
          month: 'short',
          day: 'numeric',
          year: 'numeric',
          hour: 'numeric',
          minute: '2-digit'
        });
      }
    } catch (e) {
      console.warn('Unable to format start datetime', e);
    }
    return `${dateStr}${timeStr ? ' ' + timeStr : ''}`;
  }

  function formatDurationText(task) {
    if (task.duration) return task.duration;
    if (!task.date_start || !task.date_finish) return 'N/A';
    try {
      const start = new Date(task.date_start);
      const end = new Date(task.date_finish);
      if (!isNaN(start.valueOf()) && !isNaN(end.valueOf())) {
        const diff = Math.max(0, Math.round((end - start) / (1000 * 60 * 60 * 24))) + 1;
        if (diff > 0) {
          return `${diff} day${diff > 1 ? 's' : ''}`;
        }
      }
    } catch (e) {
      console.warn('Unable to format duration', e);
    }
    return 'N/A';
  }


  function renderAvailableStaff() {
    const list = document.getElementById('availableStaffList');
    list.innerHTML = '';
    
    if (!calendarData || !calendarData.availableStaff || calendarData.availableStaff.length === 0) {
      list.innerHTML = '<p style="color: #6b7280; font-size: 14px;">No available staff at this time.</p>';
      return;
    }
    
    calendarData.availableStaff.forEach(staff => {
      const item = document.createElement('div');
      item.className = 'staff-item';
      
      const name = document.createElement('div');
      name.className = 'staff-item-name';
      name.textContent = staff.name;
      item.appendChild(name);
      
      if (staff.area_of_work) {
        const area = document.createElement('div');
        area.className = 'staff-item-area';
        area.textContent = staff.area_of_work;
        item.appendChild(area);
      }
      
      const status = document.createElement('div');
      status.className = 'staff-item-status on-duty';
      status.textContent = staff.status || 'On Duty';
      item.appendChild(status);
      
      list.appendChild(item);
    });
  }

  document.getElementById('prevMonth').addEventListener('click', () => {
    currentDate.setMonth(currentDate.getMonth() - 1);
    renderCalendar();
  });

  document.getElementById('nextMonth').addEventListener('click', () => {
    currentDate.setMonth(currentDate.getMonth() + 1);
    renderCalendar();
  });

  document.getElementById('closeModal').addEventListener('click', () => {
    document.getElementById('taskModal').style.display = 'none';
  });

  document.getElementById('taskModal').addEventListener('click', (e) => {
    if (e.target.id === 'taskModal') {
      document.getElementById('taskModal').style.display = 'none';
    }
  });

  // Initialize
  loadCalendarData();
})();
</script>

