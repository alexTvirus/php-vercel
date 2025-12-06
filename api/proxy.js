// api/proxy.js
const express = require('express');
const serverless = require('serverless-http');
const { spawn } = require('child_process');
const path = require('path');

const app = express();

app.get('/api/download-image', (req, res) => {
  const phpScriptPath = path.join(process.cwd(), 'public', 'download.php');

  // Khởi chạy tiến trình PHP-CGI (PHP Common Gateway Interface)
  // Đây là cách chính xác để chạy PHP trong môi trường serverless mà không cần webserver Apache/Nginx
  const phpProcess = spawn('php-cgi', [phpScriptPath]);

  // Khi PHP trả về STDOUT (dữ liệu phản hồi bao gồm headers và body binary)
  phpProcess.stdout.on('data', (data) => {
    // Dữ liệu từ PHP sẽ bao gồm cả tiêu đề HTTP và nội dung (headers are separated by \r\n\r\n)
    const responseText = data.toString();
    const [headersString, body] = responseText.split('\r\n\r\n');

    // Phân tích headers từ PHP và đặt lại cho response của Express
    headersString.split('\r\n').forEach(headerLine => {
      const [headerName, headerValue] = headerLine.split(': ');
      if (headerName && headerValue) {
        res.setHeader(headerName, headerValue);
      }
    });

    // Gửi body (vẫn ở dạng Buffer/binary) qua Express
    // Express/Vercel sẽ tự động nhận ra đây là binary nếu headers đúng
    res.status(200).send(Buffer.from(body)); 
  });

  phpProcess.stderr.on('data', (data) => {
    console.error(`PHP Error: ${data}`);
    res.status(500).send('Internal Server Error from PHP');
  });
});

// Cần export hàm handler cho Vercel (nếu không dùng serverless-http)
// Nếu dùng serverless-http, bạn sẽ export nó qua wrapper như ví dụ trước
module.exports = app;
module.exports.handler = serverless(app, {
  binary: ['application/json', 'image/*']
});
