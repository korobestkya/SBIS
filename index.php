<?php include 'auth.php'; ?>
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <title>Панель управления</title>
  <style>
    body { font-family: sans-serif; padding: 20px; }
    button, select {
      margin: 10px 5px 10px 0;
      padding: 10px 15px;
      font-size: 16px;
    }
    .tab { display: none; margin-top: 20px; }
    table { width: 100%; border-collapse: collapse; margin-top: 10px; }
    th, td { border: 1px solid #ccc; padding: 6px; }
    th { background-color: #eee; }
    pre { background: #f9f9f9; padding: 10px; border: 1px solid #ddd; }
    .active-tab { display: block; }
  </style>
</head>
<body>

  <h1>Панель управления выгрузкой</h1>
  <p><a href="logout.php">Выйти</a></p>

  <div>
    <button onclick="switchTab('products')">Обновить список товаров</button>
    <button onclick="switchTab('json')">Показать JSON</button>
    <button onclick="switchTab('logs')">Показать логи</button>
  </div>

  <!-- === Таб: Обновление товаров === -->
  <div id="products" class="tab">
    <h2>Результат обновления товаров</h2>
    <div id="productsOutput">Загрузка...</div>
    <script>
      fetch('position-list.php')
        .then(res => res.text())
        .then(text => {
          document.getElementById('productsOutput').textContent = text;
        })
        .catch(err => {
          document.getElementById('productsOutput').textContent = 'Ошибка: ' + err;
        });
    </script>
  </div>

  <!-- === Таб: Показ JSON === -->
  <div id="json" class="tab">
    <h2>Список товаров (JSON)</h2>
    <div id="jsonOutput">Загрузка...</div>
    <script>
      fetch('all-products.json')
        .then(res => res.json())
        .then(data => renderTable(data))
        .catch(err => {
          document.getElementById('jsonOutput').textContent = 'Ошибка: ' + err;
        });

      function renderTable(data) {
        const container = document.getElementById('jsonOutput');
        if (!Array.isArray(data) || data.length === 0) {
          container.textContent = 'Нет данных';
          return;
        }

        const headers = ['name', 'nomNumber', 'cost', 'balance', 'published'];
        const table = document.createElement('table');
        const thead = document.createElement('thead');
        const tr = document.createElement('tr');
        headers.forEach(h => {
          const th = document.createElement('th');
          th.textContent = h;
          tr.appendChild(th);
        });
        thead.appendChild(tr);
        table.appendChild(thead);

        const tbody = document.createElement('tbody');
        data.forEach(item => {
          const row = document.createElement('tr');
          headers.forEach(h => {
            const td = document.createElement('td');
            td.textContent = item[h] ?? '';
            row.appendChild(td);
          });
          tbody.appendChild(row);
        });
        table.appendChild(tbody);

        container.innerHTML = '';
        container.appendChild(table);
      }
    </script>
  </div>

  <!-- === Таб: Логи === -->
  <div id="logs" class="tab">
    <h2>Просмотр логов</h2>
    <select id="logSelect" onchange="loadLogFile(this.value)">
      <option>Загрузка...</option>
    </select>
    <pre id="logOutput" style="white-space: pre-wrap; word-wrap: break-word; overflow-wrap: break-word;"></pre>
    <script>
      fetch('list-logs.php')
        .then(res => res.json())
        .then(data => {
          console.log('Ищет логи в:', data.debugPath); // ← Вот сюда вывод в консоль
          const files = data.files;
          const select = document.getElementById('logSelect');
          select.innerHTML = '<option value="">Выберите лог...</option>';
          files.forEach(file => {
            const option = document.createElement('option');
            option.value = file;
            option.textContent = file;
            select.appendChild(option);
          });
        })
        .catch(err => {
          document.getElementById('logOutput').textContent = 'Ошибка загрузки списка: ' + err;
        });

      function loadLogFile(file) {
        if (!file) return;
        fetch('logs/' + file)
          .then(res => res.text())
          .then(text => {
            document.getElementById('logOutput').textContent = text;
          })
          .catch(err => {
            document.getElementById('logOutput').textContent = 'Ошибка загрузки лога: ' + err;
          });
      }
    </script>
  </div>

  <script>
    function switchTab(tabId) {
      document.querySelectorAll('.tab').forEach(tab => tab.style.display = 'none');
      document.getElementById(tabId).style.display = 'block';
    }

    // По умолчанию показываем вкладку JSON
    switchTab('json');
  </script>

</body>
</html>
