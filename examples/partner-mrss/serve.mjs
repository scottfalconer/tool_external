import {serveLoopback} from '../local-http.mjs';
import worker from './worker.mjs';

if (!process.env.FEED_TOKEN) throw new Error('Set FEED_TOKEN before starting the feed Worker.');
serveLoopback(request => worker.fetch(request, {FEED_TOKEN: process.env.FEED_TOKEN}));
