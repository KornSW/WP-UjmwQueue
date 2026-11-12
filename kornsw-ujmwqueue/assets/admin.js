/* KornSW UjmwQueue - live admin tabs "Processors" and "Endpoints" (refreshed every second via admin-ajax). */
(function () {
  'use strict';

  var config = window.kornswUqConfig;
  if (!config) {
    return;
  }
  var tableBody = null;
  if (config.tab === 'processors') {
    tableBody = document.getElementById('kornsw-uq-processors');
  } else if (config.tab === 'endpoints') {
    tableBody = document.getElementById('kornsw-uq-endpoints');
  }
  if (!tableBody) {
    return;
  }

  var requestSequence = 0;
  var appliedSequence = 0;
  var isRefreshing = false;

  var statusTitles = {
    idle: 'idle (polling regularly)',
    busy: 'busy',
    silent: 'no contact for 5 minutes',
    offline: 'offline (no contact for 60 minutes)'
  };
  var lockTitles = {
    none: 'Open - click to stop sending new jobs (drain)',
    draining: 'Draining (no new jobs, running jobs may finish) - click to kill the running jobs',
    locked: 'Locked - click to unlock'
  };

  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  /* Sends an AJAX action and renders the returned snapshot (responses older than the last applied one are dropped). */
  function send(action, parameters) {
    requestSequence++;
    var sequence = requestSequence;
    var body = new FormData();
    body.append('action', action);
    body.append('nonce', config.nonce);
    Object.keys(parameters).forEach(function (name) {
      body.append(name, parameters[name]);
    });
    return fetch(config.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function (response) {
        return response.json();
      })
      .then(function (result) {
        if (!result || !result.success || sequence < appliedSequence) {
          return;
        }
        appliedSequence = sequence;
        render(result.data);
      })
      .catch(function () {
        /* temporary network problems: the next refresh tries again */
      });
  }

  function refresh() {
    if (isRefreshing || document.hidden) {
      return;
    }
    isRefreshing = true;
    send('kornsw_uq_snapshot', {}).then(function () {
      isRefreshing = false;
    });
  }

  function renderLockButton(kind, id, lockState) {
    return '<button type="button" class="kornsw-uq-lock kornsw-uq-lock-' + escapeHtml(lockState) + '"'
      + ' data-action="' + kind + '-lock" data-id="' + escapeHtml(id) + '" data-expected="' + escapeHtml(lockState) + '"'
      + ' title="' + escapeHtml(lockTitles[lockState] || lockState) + '">'
      + '<svg viewBox="0 0 16 16" aria-hidden="true"><circle cx="8" cy="8" r="7"></circle><rect x="3.5" y="6.6" width="9" height="2.8" rx="0.6"></rect></svg>'
      + '</button>';
  }

  function renderInstance(instance) {
    var html = '<div class="kornsw-uq-instance">'
      + '<span class="kornsw-uq-led kornsw-uq-led-' + escapeHtml(instance.status) + '"'
      + ' title="' + escapeHtml((statusTitles[instance.status] || instance.status) + ', last contact ' + instance.lastSeen) + '"></span> '
      + '<span class="kornsw-uq-instance-name">' + escapeHtml(instance.name) + '</span> '
      + renderLockButton('processor', instance.id, instance.lock)
      + ' <span class="kornsw-uq-count">(' + escapeHtml(instance.active) + ')</span>';
    if (instance.active > 0) {
      html += ' <a href="#" class="kornsw-uq-cancel" data-action="processor-cancel" data-id="' + escapeHtml(instance.id) + '">[CANCEL]</a>';
    }
    return html + '</div>';
  }

  function buildProcessorRows(processors) {
    return processors.map(function (processor) {
      var endpoints = '&mdash;';
      if (processor.endpoints.length > 0) {
        endpoints = processor.endpoints.map(function (route) {
          return '<code>' + escapeHtml(route) + '</code>';
        }).join('<br>');
      }
      return {
        key: 'a:' + processor.agent,
        cells: [
          '<strong>' + escapeHtml(processor.agent) + '</strong>',
          processor.instances.map(renderInstance).join(''),
          endpoints
        ]
      };
    });
  }

  function buildEndpointRows(endpoints) {
    return endpoints.map(function (endpoint) {
      var queuedClass = 'kornsw-uq-zero';
      if (endpoint.queued > 0) {
        queuedClass = 'kornsw-uq-queued';
        if (endpoint.unserved) {
          queuedClass = 'kornsw-uq-unserved';
        }
      }
      var activeClass = 'kornsw-uq-zero';
      if (endpoint.active > 0) {
        activeClass = 'kornsw-uq-active';
      }
      var agents = '&mdash;';
      if (endpoint.agents.length > 0) {
        agents = endpoint.agents.map(escapeHtml).join('<br>');
      }
      var checked = '';
      if (endpoint.monitoring) {
        checked = ' checked';
      }
      var jobsTitle = 'waiting / active';
      if (endpoint.unserved) {
        jobsTitle += ' - no processor available';
      }
      return {
        key: 'q:' + endpoint.id,
        cells: [
          '<code>' + escapeHtml(endpoint.route) + '</code> ' + renderLockButton('endpoint', endpoint.id, endpoint.lock)
            + '<div class="kornsw-uq-row-actions"><a href="#" data-action="endpoint-delete" data-id="' + escapeHtml(endpoint.id) + '">Delete</a></div>',
          '<div class="kornsw-uq-methods">' + endpoint.methods.map(escapeHtml).join('<br>') + '</div>',
          '<span title="' + escapeHtml(jobsTitle) + '"><span class="' + queuedClass + '">' + escapeHtml(endpoint.queued) + '</span>'
            + ' / <span class="' + activeClass + '">' + escapeHtml(endpoint.active) + '</span></span>',
          agents,
          '<label class="kornsw-uq-switch" title="Email notification on overflow / no processor"><input type="checkbox" data-action="endpoint-monitoring" data-id="'
            + escapeHtml(endpoint.id) + '"' + checked + '><span></span></label>'
        ]
      };
    });
  }

  /* Updates only the cells whose HTML changed (keeps scroll positions and running animations). */
  function patchRows(rows, columnCount, emptyText) {
    if (rows.length === 0) {
      rows = [{ key: 'empty', cells: [emptyText], colspan: columnCount }];
    }
    var existingRows = tableBody.rows;
    var isSameStructure = (existingRows.length === rows.length);
    if (isSameStructure) {
      for (var index = 0; index < rows.length; index++) {
        if (existingRows[index].getAttribute('data-key') !== rows[index].key) {
          isSameStructure = false;
          break;
        }
      }
    }
    if (!isSameStructure) {
      tableBody.innerHTML = rows.map(function (row) {
        var colspan = '';
        if (row.colspan) {
          colspan = ' colspan="' + row.colspan + '"';
        }
        return '<tr data-key="' + escapeHtml(row.key) + '">' + row.cells.map(function (cell) {
          return '<td' + colspan + '>' + cell + '</td>';
        }).join('') + '</tr>';
      }).join('');
      Array.prototype.forEach.call(tableBody.rows, function (tableRow, rowIndex) {
        Array.prototype.forEach.call(tableRow.cells, function (tableCell, cellIndex) {
          tableCell.kornswUqHtml = rows[rowIndex].cells[cellIndex];
        });
      });
      return;
    }
    rows.forEach(function (row, rowIndex) {
      row.cells.forEach(function (cellHtml, cellIndex) {
        var tableCell = existingRows[rowIndex].cells[cellIndex];
        if (tableCell && tableCell.kornswUqHtml !== cellHtml) {
          tableCell.innerHTML = cellHtml;
          tableCell.kornswUqHtml = cellHtml;
        }
      });
    });
  }

  function render(snapshot) {
    if (config.tab === 'processors') {
      patchRows(buildProcessorRows(snapshot.processors), 3, 'No processor has connected yet.');
    } else {
      patchRows(buildEndpointRows(snapshot.endpoints), 5, 'No endpoint has been learned yet. Connect a processor to <code>&lt;endpoint&gt;/_queue</code>.');
    }
  }

  tableBody.addEventListener('click', function (event) {
    var target = event.target.closest('[data-action]');
    if (!target || target.tagName === 'INPUT') {
      return;
    }
    event.preventDefault();
    var action = target.getAttribute('data-action');
    var id = target.getAttribute('data-id');
    var expected = target.getAttribute('data-expected');

    if (action === 'processor-lock' || action === 'endpoint-lock') {
      if (expected === 'draining' && !window.confirm('Kill the running jobs? Callers receive a fault, the processor is asked to cancel.')) {
        return;
      }
      send('kornsw_uq_' + action.replace('-', '_'), { id: id, expected: expected });
    } else if (action === 'processor-cancel') {
      if (window.confirm('Cancel all active jobs of this instance? Callers receive a fault.')) {
        send('kornsw_uq_processor_cancel', { id: id });
      }
    } else if (action === 'endpoint-delete') {
      if (window.confirm('Delete this endpoint including all queued and running jobs? It is learned again on the next processor contact.')) {
        send('kornsw_uq_endpoint_delete', { id: id });
      }
    }
  });

  tableBody.addEventListener('change', function (event) {
    var target = event.target;
    if (target.getAttribute('data-action') !== 'endpoint-monitoring') {
      return;
    }
    var enabled = '0';
    if (target.checked) {
      enabled = '1';
    }
    send('kornsw_uq_endpoint_monitoring', { id: target.getAttribute('data-id'), enabled: enabled });
  });

  refresh();
  window.setInterval(refresh, config.refreshMs);
}());
