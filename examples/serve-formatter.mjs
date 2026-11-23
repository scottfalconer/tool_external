import {serveLoopback} from './local-http.mjs';
import formatter from './normalize-title.mjs';

if (!process.env.FORMATTER_TOKEN) throw new Error('Set FORMATTER_TOKEN before starting the formatter.');
serveLoopback(request => formatter.fetch(request, process.env));
