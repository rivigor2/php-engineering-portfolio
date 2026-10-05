const request       = require("request");
const util          = require('util');
const exec          = require('child_process').execSync;
const os            = require("os");
let path            = require('path');
var https           = require('https');
var http            = require('http');
const express       = require('express');
const argv          = require('minimist')(process.argv.slice(2));
const {Docker}      = require('node-docker-api');
let fs              = require('fs');
const bodyParser = require('body-parser');
const WebSocket = require('ws');


class Swarm {
    constructor() {
        this.ipAddress      = Swarm.getIPAddress();
        this.wss = new WebSocket.Server({
            noServer: true,
        });
        this.wsClients = new Set();
        this.apiPort = 2121;
        this.logPath = '/srv/icdm/logs/swarm.log';
    }

    async start () {
        this.logger('Swarm: start');
        this.docker         = new Docker({ socketPath: '/var/run/docker.sock' });
        this.lookForJob();
        this.startApiServer();
    }

    async restartDroid(droidName){
        let droids = await this.getDroidsList();
        for (let i = 0 ; i < droids.length ; i++) {
            if (droids[i].name === '/' + droidName) {
                droids[i].container.stop();
                await this.wait(5000);
                droids[i].container.start();
                await this.wait(5000);
                this.logger('Swarm: restartDroid: droidName=' + droids[i].name);
            }
        }
    }

    async startDroid(phone) {
        this.logger('Swarm: startDroid: start: phone=' + phone);
        let container = null;
        container = await this.newDroid(phone)
        try {
            await this.wait(3000);
            await container.start();
            await this.wait(3000);
            this.logger('Swarm: startDroid: end=true: phone=' + phone);
            return true;
        } catch (e) {
           this.logger('Swarm: startDroid: end=false: phone=' + phone + ':error=' + e.toString());
           return false;
        }
    }

    logger(msg) {
        var msgToFile = msg.toString();
        let ts = Date.now();
        let date_ob = new Date(ts);
        let date = date_ob.getDate();
        let month = date_ob.getMonth() + 1;
        let year = date_ob.getFullYear();
        let hours = date_ob.getHours();
        let minutes = date_ob.getMinutes();
        let seconds = date_ob.getSeconds();

        msgToFile = year + "-" + month + "-" + date + "|" + hours + ":" + minutes + ":" + seconds + "-> " + msgToFile;

        if (fs.existsSync(this.logPath)) {
            fs.appendFileSync(this.logPath, msgToFile + '\n');
            return true;
        }  else {
            var log_file = fs.createWriteStream(this.logPath, {flags : 'w'});
            log_file.write(msgToFile + '\n');
            return true;
        }
    }

    async startApiServer() {
        return new Promise((resolve, reject) => {
            this.express = express();
            this.express.use(bodyParser.json());
            const server = http.createServer(this.express);

            this.express.on('error', (e) => {
                this.logger('Swarm: startApiServer: error');
                reject();
            });

            this.express.get('/ping/', async (req, res) => {
                this.logger('Swarm: startApiServer: /ping/: start');
                this.prepareRes(res);
                res.header("Content-Type", "text/plain; charset=utf-8");
                this.logger('Swarm: startApiServer: /ping/: end=true');
                return res.send('true').end();
            });

            this.express.get('/start_droid/:phone', async (req, res) => {
                this.logger('Swarm: startApiServer: /start_droid/:phone: start');
                this.prepareRes(res);
                res.header("Content-Type", "text/plain; charset=utf-8");
                if (req.params.phone) {
                    let result_start = await this.startDroid(req.params.phone);
                    this.logger('Swarm: startApiServer: /start_droid/:phone=' + req.params.phone);
                    this.logger('Swarm: startApiServer: /start_droid/:result_start=' + result_start);
                    if (result_start === true) {
                        this.logger('Swarm: startApiServer: /start_droid/:end=true');
                        return res.send('true').end();
                    }
                }
                this.logger('Swarm: startApiServer: /start_droid/:phone: end=false');
                return res.send('false').end();
            });

            this.express.get('/stop_droid/:phone', async (req, res) => {
                this.logger('Swarm: startApiServer: /stop_droid/:phone: start');
                this.prepareRes(res);
                res.header("Content-Type", "text/plain; charset=utf-8");
                if (req.params.phone) {
                    let droidName = this.createName(req.params.phone);
                    await this.killDroid(droidName);
                    this.logger('Swarm: startApiServer: /stop_droid/:phone: end=true: phone=' + req.params.phone);
                    return res.send('true').end();
                }
                this.logger('Swarm: startApiServer: /stop_droid/:phone: end=false');
                return res.send('false').end();
            });

            this.express.get('/check/:phone', async (req, res) => {
                this.logger('Swarm: startApiServer: /check/:phone: start');
                this.prepareRes(res);
                res.header("Content-Type", "text/plain; charset=utf-8");
                if (req.params.phone) {
                    if (await this.checkDroid(this.createName(req.params.phone)) === true) {
                        this.logger('Swarm: startApiServer: /check/:phone: end=true: phone=' + req.params.phone);
                        return res.send('true');
                    }
                    this.logger('Swarm: startApiServer: /check/:phone: end=false: phone=' + req.params.phone);
                    return res.send('false').end();
                }
                this.logger('Swarm: startApiServer: /check/:phone: end=false');
                return res.send('false').end();
            });

            this.express.get('/restart_droid/:phone', async (req, res) => {
                this.logger('Swarm: startApiServer: /restart_droid/:phone: start');
                this.prepareRes(res);
                res.header("Content-Type", "text/plain; charset=utf-8");
                if (req.params.phone) {
                    let droidName = this.createName(req.params.phone);
                    let result_start = await this.restartDroid(droidName);
                    this.logger('Swarm: startApiServer: /restart_droid/:phone=' + req.params.phone);
                    this.logger('Swarm: startApiServer: /restart_droid/:result_start=' + result_start);
                    if (result_start === true) {
                        this.logger('Swarm: startApiServer: /restart_droid/:end=true');
                        return res.send('true').end();
                    }
                }
                this.logger('Swarm: startApiServer: /restart_droid/:phone: end=false');
                return res.send('false').end();
            });

            this.express.use(async(req, res, next) => {
                this.prepareRes(res);
                next();
            });

            server.listen(this.apiPort, () => {
                this.logger('Swarm: startApiServer: started');
                console.log('Api server listening on port '+this.ipAddress+':' + this.apiPort);
                resolve();
            });
        });

    }

    prepareRes(res) {
        res.header("Access-Control-Allow-Origin", "*");
        res.header("Access-Control-Allow-Headers", "Origin, X-Requested-With, Content-Type, Accept");
        res.header("Content-Type", "application/json; charset=utf-8");
    }

    static getIPAddress() {
        let interfaces = os.networkInterfaces();
        for (let devName in interfaces) {
            if (interfaces.hasOwnProperty(devName)) {
                let iface = interfaces[devName];

                for (let i = 0; i < iface.length; i++) {
                    let alias = iface[i];
                    if (alias.family === 'IPv4' && alias.address !== '127.0.0.1' && !alias.internal)
                        return alias.address;
                }
            }
        }

        return '0.0.0.0';
    }

    async getDroidsList() {
        return new Promise(async (resolve, reject) => {
            let droids = [];
            try {
                const containers = await this.docker.container.list({
                    all: 1
                }).catch(reason => {});

                if (!Array.isArray(containers)) {
                    resolve([]);
                }

                let droidsCount = 0;
                for (let i = 0; i < containers.length; i++) {
                    if (!Array.isArray(containers[i].data.Names) ||
                        containers[i].data.Names.length === 0 ||
                        !containers[i].data.Names[0].includes('parsing-')) {
                        continue;
                    }

                    droidsCount++;

                    let droid = {
                        id: containers[i].data.Id,
                        container: containers[i],
                        name: containers[i].data.Names[0],
                        state: containers[i].data.State,
                    };

                    droids.push(droid);

                }
                this.droidsCount = droidsCount;
                resolve(droids);

            } catch (e) {
                console.log(e);
                resolve([]);
            }
        });
    }


    async lookForJob() {
        setTimeout(() => {
            this.lookForJob();
        }, 1000);
    }

    async wait (t){
        await new Promise((resolve, reject) => {
            setTimeout(() => {
                resolve()
            }, t);
        });
    }

    createName(phone) {
        return 'parsing-' + phone;
    }

    async newDroid(phone) {
        this.logger('Swarm: newDroid: start: phone=' + phone);
        let droidName = this.createName(phone);
        await this.killDroid(droidName);

        this.wait(3000);

        let config = {
            Image: 'trafex/php-nginx',
            name: droidName,
            HostConfig: {
                "Privileged": true,
                "CapAdd": [
                    "NET_ADMIN"
                ],
                "Sysctls": {
                    "net.ipv6.conf.all.disable_ipv6": "0"
                },
                "MemorySwap": -1,
                "Memory": 2 * 1024 * 1024 * 1024,
                "ShmSize": 2 * 1024 * 1024 * 1024,
            }
        };

        let droid = null;
        droid = await this.docker.container.create(config).catch(reason => {
            this.logger('Swarm: newDroid: error: phone=' + phone + ': reason = ' + reason.toString());
        });
        this.logger('Swarm: newDroid: end: phone=' + phone);
        return droid;
    }

    async killDroid(droidName) {
        this.logger('Swarm: killDroid: start: droidName=' + droidName);
        let droids = await this.getDroidsList();
        for (let i = 0 ; i < droids.length ; i++) {
            if (droids[i].name === '/' + droidName) {
                await this.kill(droids[i]);
                this.logger('Swarm: killDroid: end: droidName=' + droids[i].name);
            }
        }
    }

    async checkDroid(droidName) {
        this.logger('Swarm: checkDroid: start: droidName=' + droidName);
        let droids = await this.getDroidsList();
        for (let i = 0 ; i < droids.length ; i++) {
            if (droids[i].name === '/' + droidName && droids[i].state === 'running') {
                this.logger('Swarm: checkDroid: end=true: droidName=' + droidName);
                return true;
            }
        }
        this.logger('Swarm: checkDroid: end=false: droidName=' + droidName);
        return false;
    }

    async kill(droid) {
        this.logger('Swarm: kill: start');
        try {
            await droid.container.stop();
            await droid.container.delete({ force: true });
            this.logger('Swarm: kill: stop');
        } catch (e) {
            this.logger('Swarm: kill: error=' + e.toString());
        }
    }

}

const swarm = new Swarm();

swarm.start();
