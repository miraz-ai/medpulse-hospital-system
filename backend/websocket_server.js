/**
 * MedPulse Enterprise Hospital Management System
 * Turnkey WebSocket / Real-Time Dispatcher Server (Node.js)
 * 
 * Provides WebSocket connectivity for Doctor and Patient dashboards.
 * Clients join specific rooms (e.g. 'doctor:21', 'patient:18', 'admin:global')
 * and receive typed real-time events (PATIENT_BED_MOVED, BED_ASSIGNED, PATIENT_DISCHARGED).
 * 
 * Usage:
 *   node backend/websocket_server.js
 */

const http = require('http');
const fs = require('fs');
const path = require('path');
const { WebSocketServer } = require('ws');

const PORT = process.env.WS_PORT || 8080;
const EVENT_STREAM_PATH = path.join(__dirname, '../storage/events/events_stream.jsonl');

const server = http.createServer((req, res) => {
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ status: 'MedPulse WebSocket Server Operational', port: PORT }));
});

const wss = new WebSocketServer({ server });

// Map of room -> Set of WebSocket clients
const rooms = new Map();

function joinRoom(room, ws) {
    if (!rooms.has(room)) {
        rooms.set(room, new Set());
    }
    rooms.get(room).add(ws);
    if (!ws.rooms) ws.rooms = new Set();
    ws.rooms.add(room);
}

function leaveAllRooms(ws) {
    if (ws.rooms) {
        for (const room of ws.rooms) {
            const clientSet = rooms.get(room);
            if (clientSet) {
                clientSet.delete(ws);
                if (clientSet.size === 0) rooms.delete(room);
            }
        }
    }
}

function broadcastToRoom(room, eventName, data) {
    const clients = rooms.get(room);
    if (!clients) return;

    const payload = JSON.stringify({
        event: eventName,
        room: room,
        timestamp: new Date().toISOString(),
        payload: data
    });

    for (const client of clients) {
        if (client.readyState === 1) { // OPEN
            client.send(payload);
        }
    }
}

wss.on('connection', (ws, req) => {
    // Parse URL params for auto-join: ws://localhost:8080?channel=doctor:21
    const url = new URL(req.url, `http://${req.headers.host}`);
    const channel = url.searchParams.get('channel');
    if (channel) {
        joinRoom(channel, ws);
    }

    ws.on('message', (message) => {
        try {
            const data = JSON.parse(message.toString());
            if (data.action === 'SUBSCRIBE' && data.channel) {
                joinRoom(data.channel, ws);
                ws.send(JSON.stringify({ event: 'SUBSCRIBED', channel: data.channel }));
            } else if (data.action === 'PING') {
                ws.send(JSON.stringify({ event: 'PONG', timestamp: Date.now() }));
            }
        } catch (err) {
            console.error('Invalid message from client:', err.message);
        }
    });

    ws.on('close', () => {
        leaveAllRooms(ws);
    });

    ws.send(JSON.stringify({
        event: 'CONNECTED',
        message: 'Connected to MedPulse Clinical Real-Time Bus'
    }));
});

// File watcher to stream events from PHP EventDispatcher
let filePosition = 0;
if (fs.existsSync(EVENT_STREAM_PATH)) {
    filePosition = fs.statSync(EVENT_STREAM_PATH).size;
}

fs.watchFile(EVENT_STREAM_PATH, { interval: 300 }, (curr) => {
    if (curr.size > filePosition) {
        const stream = fs.createReadStream(EVENT_STREAM_PATH, {
            start: filePosition,
            end: curr.size
        });

        let buffer = '';
        stream.on('data', (chunk) => { buffer += chunk; });
        stream.on('end', () => {
            filePosition = curr.size;
            const lines = buffer.split('\n').filter(Boolean);
            for (const line of lines) {
                try {
                    const eventObj = JSON.parse(line);
                    // Broadcast to targeted channel
                    if (eventObj.channel) {
                        broadcastToRoom(eventObj.channel, eventObj.event, eventObj.data || eventObj);
                    }
                    // Also broadcast to admin:global
                    broadcastToRoom('admin:global', eventObj.event, eventObj.data || eventObj);
                } catch (e) {
                    console.error('Error parsing stream line:', e.message);
                }
            }
        });
    }
});

server.listen(PORT, () => {
    console.log(`[MedPulse] WebSocket Dispatcher running on ws://localhost:${PORT}`);
});
