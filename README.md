# ConcurrencyStopper
This class provide tools to stop concurrent execution
of a script even in a rare case of parallel init of the
same script (I know it seems crazy, but it happens when
multiple crond services run on a server)

Notes: PHP < 5.6.1 does not support any method for querying
the state of a semaphore in a non-blocking manner. So we
have to trick it by serializing semaphore using SHM vars.
